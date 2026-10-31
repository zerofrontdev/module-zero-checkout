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

/**
 * Runs an update of one redirect-payment order under its lock, on the order
 * as it is once the lock is held: another process (webhook, return, cron)
 * may have settled it meanwhile. The same process may nest: a second run()
 * for the same order takes no lock and gets the same order object.
 */
class OrderLock
{
    public const PREFIX = 'zero_redirect_payment_';

    /** @var array<string, int> lock name => nesting depth in this process */
    private array $depth = [];
    /** @var array<string, Order> lock name => the order the outermost run() reloaded */
    private array $fresh = [];

    public function __construct(
        private readonly LockManagerInterface $locks,
        private readonly CollectionFactory $orders
    ) {
    }

    /**
     * @template T
     * @param callable(Order): T $fn gets the order reloaded under the lock
     * @param int $wait seconds to wait for the lock (0: not at all)
     * @return T
     * @throws OrderLockedException when another process holds the lock
     */
    public function run(Order $order, callable $fn, int $wait = 10): mixed
    {
        $name = self::PREFIX . $order->getIncrementId();
        if (($this->depth[$name] ?? 0) > 0) {
            $this->depth[$name]++;
            try {
                return $fn($this->fresh[$name]);
            } finally {
                $this->depth[$name]--;
            }
        }
        if (!$this->locks->lock($name, $wait)) {
            throw new OrderLockedException((string) $order->getIncrementId());
        }
        $this->depth[$name] = 1;
        try {
            // Not OrderRepository::get(): it caches per request and returns the stale object.
            $fresh = $this->orders->create()->addFieldToFilter('entity_id', (int) $order->getId())->getFirstItem();
            $this->fresh[$name] = $fresh->getId() ? $fresh : $order;

            return $fn($this->fresh[$name]);
        } finally {
            unset($this->depth[$name], $this->fresh[$name]);
            $this->locks->unlock($name);
        }
    }
}
