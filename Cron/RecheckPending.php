<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\Config;
use Zero\Checkout\Model\RedirectPayment\OrderLock;
use Zero\Checkout\Model\RedirectPayment\OrderLockedException;
use Zero\Checkout\Model\RedirectPayment\Updaters;

/**
 * Payment Webhooks > Recheck Pending Payments: a webhook the store never
 * received (down, 503 five times, filtered) leaves a paid order in
 * pending_payment until Magento cancels it, without asking the provider, after
 * Sales > Orders Cron Settings > Pending Payment Order Lifetime (8 hours by default). Every 15 minutes this
 * asks the provider about those orders, 10 minutes to 7 hours old, for the
 * payment methods registered in Updaters. It never cancels by itself: an
 * updater may, when the provider reports a definitive failure.
 */
class RecheckPending
{
    /** A customer may still be on the provider's page this long after placing the order. */
    public const MIN_AGE = 600;
    /** Magento's sales_clean_orders cancels after the Pending Payment Order Lifetime (8 hours by default); the last ask is an hour before. */
    public const MAX_AGE = 25200;
    public const LIMIT = 200;

    public function __construct(
        private readonly Updaters $updaters,
        private readonly OrderLock $orderLock,
        private readonly CollectionFactory $orders,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $updaters = $this->updaters->all();
        if (!$this->config->isRecheckPending() || $updaters === []) {
            return;
        }
        $collection = $this->orders->create()
            ->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('main_table.created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - self::MIN_AGE)])
            ->addFieldToFilter('main_table.created_at', ['gt' => gmdate('Y-m-d H:i:s', time() - self::MAX_AGE)])
            ->setOrder('main_table.created_at', 'ASC')
            ->setPageSize(self::LIMIT);
        $collection->getSelect()->join(
            ['p' => $collection->getTable('sales_order_payment')],
            'p.parent_id = main_table.entity_id',
            []
        )->where('p.method IN (?)', array_keys($updaters));

        foreach ($collection as $order) {
            $method = (string) $order->getPayment()?->getMethod();
            $updater = $updaters[$method] ?? null;
            if ($updater === null) {
                continue;
            }
            try {
                $this->orderLock->run($order, static fn (Order $fresh) => $updater->update($fresh, []), 0);
            } catch (OrderLockedException) {
                continue;
            } catch (LocalizedException $e) {
                $this->logger->info(sprintf('Zero Checkout: recheck of order %s (%s): %s', $order->getIncrementId(), $method, $e->getMessage()));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Zero Checkout: recheck of order %s (%s): %s: %s', $order->getIncrementId(), $method, $e::class, $e->getMessage()));
            }
        }
    }
}
