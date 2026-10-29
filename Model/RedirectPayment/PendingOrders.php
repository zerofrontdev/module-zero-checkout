<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * For a redirect payment without webhooks (Afterpay, Zip): the orders still
 * waiting for the provider's answer, rechecked by a cron, and given up after
 * a while. Every update of an order (by the cron or by the customer coming
 * back) runs under the order's lock, so the two never capture twice.
 */
class PendingOrders
{
    /** A customer may still be on the provider's page this long after placing the order. */
    public const MIN_AGE = 600;
    /** Rechecked this long; older orders are cancelled by expire(). */
    public const MAX_AGE = 2 * 86400;
    private const LOCK = 'zero_redirect_payment_';

    public function __construct(
        private readonly CollectionFactory $orders,
        private readonly LockManagerInterface $locks,
        private readonly OrderPayment $orderPayment,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Runs $update for the order under its lock, the order reloaded once the
     * lock is held (another process may have settled it meanwhile).
     *
     * @template T
     * @param callable(Order): T $update
     * @return T
     */
    public function locked(Order $order, callable $update): mixed
    {
        $name = self::LOCK . $order->getIncrementId();
        if (!$this->locks->lock($name, 10)) {
            throw new \RuntimeException('Order ' . $order->getIncrementId() . ' is being updated by another process.');
        }
        try {
            $fresh = $this->orders->create()->addFieldToFilter('entity_id', (int) $order->getId())->getFirstItem();
            return $update($fresh->getId() ? $fresh : $order);
        } finally {
            $this->locks->unlock($name);
        }
    }

    /**
     * Rechecks every pending order of a method placed between MIN_AGE and
     * MAX_AGE ago (the cron's job); then cancels the ones older than
     * $expireAfter seconds the provider never confirmed, so an abandoned
     * checkout doesn't hold the stock forever.
     *
     * @param callable(Order): mixed $update
     */
    public function recheck(string $methodCode, string $provider, callable $update, int $expireAfter = self::MAX_AGE): void
    {
        foreach ($this->pending($methodCode, self::MIN_AGE, self::MAX_AGE) as $order) {
            try {
                $this->locked($order, $update);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Zero %s: recheck of order %s: %s', $provider, $order->getIncrementId(), $e->getMessage()));
            }
        }
        foreach ($this->pending($methodCode, $expireAfter, 30 * 86400) as $order) {
            try {
                $this->locked($order, function (Order $order) use ($provider, $expireAfter): void {
                    if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                        $this->orderPayment->cancel($order, (string) __('%1 never confirmed the payment: given up after %2 hours.', $provider, (int) round($expireAfter / 3600)));
                    }
                });
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Zero %s: expiry of order %s: %s', $provider, $order->getIncrementId(), $e->getMessage()));
            }
        }
    }

    /**
     * @return iterable<Order>
     */
    private function pending(string $methodCode, int $minAge, int $maxAge): iterable
    {
        $collection = $this->orders->create()
            ->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('main_table.created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - $minAge)])
            ->addFieldToFilter('main_table.created_at', ['gt' => gmdate('Y-m-d H:i:s', time() - $maxAge)])
            ->setPageSize(100);
        $collection->getSelect()->join(
            ['p' => $collection->getTable('sales_order_payment')],
            'p.parent_id = main_table.entity_id',
            []
        )->where('p.method = ?', $methodCode);

        return $collection;
    }
}
