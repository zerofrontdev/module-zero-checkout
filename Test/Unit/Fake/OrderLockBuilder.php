<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Fake;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Zero\Checkout\Model\RedirectPayment\OrderLock;

/**
 * A real OrderLock over a FakeLockManager, for a TestCase. The order
 * collection it reloads from is the only stub: filtered by entity_id, it
 * returns the order given for that id in $fresh, else an empty item (OrderLock
 * then keeps the order it was passed).
 */
trait OrderLockBuilder
{
    /**
     * @param array<int, Order> $fresh entity id => the order "in the database" once the lock is held
     */
    private function orderLock(FakeLockManager $locks, array $fresh = []): OrderLock
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($fresh): Collection {
            $id = 0;
            $collection = $this->createStub(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $value) use (&$id, $collection): Collection {
                    if ($field === 'entity_id') {
                        $id = (int) $value;
                    }

                    return $collection;
                }
            );
            $collection->method('getFirstItem')->willReturnCallback(
                static function () use (&$id, $fresh): DataObject {
                    return $fresh[$id] ?? new DataObject();
                }
            );

            return $collection;
        });

        return new OrderLock($locks, $factory);
    }
}
