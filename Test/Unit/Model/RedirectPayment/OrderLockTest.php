<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\OrderLockedException;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * One update of a redirect-payment order at a time: the lock is named after
 * the order number, the update gets the order as reloaded once the lock is
 * held, a nested run for the same order locks once, and the lock is always
 * released.
 */
class OrderLockTest extends TestCase
{
    use OrderLockBuilder;

    private FakeLockManager $locks;

    protected function setUp(): void
    {
        $this->locks = new FakeLockManager();
    }

    public function testRunsOnTheFreshOrderUnderTheLock(): void
    {
        $stale = $this->order(12, '100000001');
        $fresh = $this->order(12, '100000001');
        $lock = $this->orderLock($this->locks, [12 => $fresh]);

        $result = $lock->run($stale, function (Order $order) use ($fresh): string {
            $this->assertSame($fresh, $order);
            $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000001'));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame([['lock', 'zero_redirect_payment_100000001', 10]], $this->locks->locks());
        $this->assertSame([['unlock', 'zero_redirect_payment_100000001']], $this->locks->unlocks());
        $this->assertSame([], $this->locks->held);
    }

    public function testThePassedOrderIsUsedWhenTheCollectionFindsNothing(): void
    {
        $order = $this->order(12, '100000001');

        $seen = $this->orderLock($this->locks)->run($order, static fn (Order $o) => $o, 0);

        $this->assertSame($order, $seen);
        $this->assertSame([['lock', 'zero_redirect_payment_100000001', 0]], $this->locks->locks());
    }

    public function testHeldByAnotherProcessThrowsOrderLocked(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000001');
        $called = false;

        try {
            $this->orderLock($this->locks)->run($this->order(12, '100000001'), function () use (&$called): void {
                $called = true;
            });
            $this->fail('OrderLockedException expected');
        } catch (OrderLockedException $e) {
            $this->assertInstanceOf(LocalizedException::class, $e);
            $this->assertStringContainsString('100000001', $e->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame([], $this->locks->unlocks());
    }

    public function testNestedRunForTheSameOrderLocksOnce(): void
    {
        $fresh = $this->order(12, '100000001');
        $lock = $this->orderLock($this->locks, [12 => $fresh]);
        $outer = $this->order(12, '100000001');

        $lock->run($outer, function (Order $order) use ($lock, $fresh): void {
            $inner = $lock->run($this->order(12, '100000001'), static fn (Order $o) => $o);
            $this->assertSame($fresh, $inner);
            $this->assertSame([], $this->locks->unlocks(), 'the inner run must not unlock');
        });

        $this->assertCount(1, $this->locks->locks());
        $this->assertCount(1, $this->locks->unlocks());
        $this->assertSame([], $this->locks->held);
    }

    public function testAnotherOrderInsideARunTakesItsOwnLock(): void
    {
        $lock = $this->orderLock($this->locks);

        $lock->run($this->order(12, '100000001'), function () use ($lock): void {
            $lock->run($this->order(13, '100000002'), static fn () => null);
        });

        $this->assertSame(
            ['zero_redirect_payment_100000001', 'zero_redirect_payment_100000002'],
            array_column($this->locks->locks(), 1)
        );
        $this->assertSame([], $this->locks->held);
    }

    public function testUnlocksWhenTheCallbackThrows(): void
    {
        $lock = $this->orderLock($this->locks);

        try {
            $lock->run($this->order(12, '100000001'), static function (): void {
                throw new \DomainException('boom');
            });
            $this->fail('the exception must pass through');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame([], $this->locks->held);
        $this->assertCount(1, $this->locks->unlocks());

        // The depth was reset: the next run locks again.
        $lock->run($this->order(12, '100000001'), static fn () => null);
        $this->assertCount(2, $this->locks->locks());
    }

    public function testInnerThrowLeavesTheOuterLockToTheOuterRun(): void
    {
        $lock = $this->orderLock($this->locks);

        $lock->run($this->order(12, '100000001'), function () use ($lock): void {
            try {
                $lock->run($this->order(12, '100000001'), static function (): void {
                    throw new \DomainException('inner');
                });
            } catch (\DomainException) {
                $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000001'));
            }
        });

        $this->assertCount(1, $this->locks->locks());
        $this->assertCount(1, $this->locks->unlocks());
    }

    private function order(int $id, string $increment): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($increment);

        return $order;
    }
}
