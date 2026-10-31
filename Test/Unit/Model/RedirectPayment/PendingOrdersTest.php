<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\PendingOrders;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * The Afterpay/Zip cron: pending orders are rechecked under their lock, and
 * an order past its lifetime is cancelled only once the provider, asked once
 * more, still reports nothing; a provider that can't be asked keeps it.
 *
 *   cron -> PendingOrders::recheck -> [recheck list] OrderLock -> $update(fresh)
 *                                  -> [expiry list]  OrderLock -> OrderPayment::cancelUnlessPaid
 *                                                     -> $update(fresh) (ask) -> still pending ? cancel
 */
#[AllowMockObjectsWithoutExpectations]
class PendingOrdersTest extends TestCase
{
    use OrderLockBuilder;

    private FakeLockManager $locks;
    private OrderRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    /** @var list<list<Order>> what each pending() query returns, in order: recheck, then expiry */
    private array $lists = [];
    private string $state = Order::STATE_PENDING_PAYMENT;
    /** @var list<string> */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->locks = new FakeLockManager();
        $this->repository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testExpiryAsksTheProviderBeforeCancelling(): void
    {
        $order = $this->order();
        $this->lists = [[], [$order]];
        $order->expects($this->once())->method('registerCancellation')
            ->with($this->logicalAnd($this->stringContains('Afterpay never confirmed'), $this->stringContains('48 hours')));
        $this->repository->expects($this->once())->method('save')->with($order);

        $this->pendingOrders()->recheck('zero_afterpay', 'Afterpay', function (Order $fresh): void {
            $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000001'));
            $this->steps[] = 'ask';
        });

        $this->assertSame(['ask', 'cancel'], $this->steps);
        $this->assertSame(Order::STATE_CANCELED, $this->state);
        $this->assertSame([], $this->locks->held);
    }

    public function testExpiryDoesNotCancelWhenTheProviderIsDown(): void
    {
        $order = $this->order();
        $this->lists = [[], [$order]];
        $order->expects($this->never())->method('registerCancellation');
        $this->repository->expects($this->never())->method('save');

        $this->pendingOrders()->recheck('zero_afterpay', 'Afterpay', static function (): void {
            throw new LocalizedException(__('Afterpay is down'));
        });

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->state);
    }

    public function testExpiryKeepsAnOrderTheProviderConfirmed(): void
    {
        $order = $this->order();
        $this->lists = [[], [$order]];
        $order->expects($this->never())->method('registerCancellation');

        $this->pendingOrders()->recheck('zero_zip', 'Zip', function (): void {
            $this->state = Order::STATE_PROCESSING;
        });
    }

    public function testRecheckRunsEveryOrderUnderItsLockAndALockedOneIsLogged(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000002');
        $this->lists = [[$this->order(2, '100000002'), $this->order()], []];
        $this->logger->expects($this->once())->method('warning')
            ->with($this->logicalAnd($this->stringContains('recheck of order 100000002'), $this->stringContains('another process')));
        $seen = [];

        $this->pendingOrders()->recheck('zero_zip', 'Zip', static function (Order $fresh) use (&$seen): void {
            $seen[] = $fresh->getIncrementId();
        });

        $this->assertSame(['100000001'], $seen);
        $this->assertSame([], $this->locks->held);
    }

    private function pendingOrders(): PendingOrders
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(fn () => $this->collection(array_shift($this->lists) ?? []));
        $orderPayment = new OrderPayment(
            $this->repository,
            $this->createStub(OrderSender::class),
            $this->createStub(NotifierInterface::class),
            $this->logger
        );

        return new PendingOrders($factory, $this->orderLock($this->locks), $orderPayment, $this->logger);
    }

    /**
     * @param list<Order> $orders
     */
    private function collection(array $orders): Collection
    {
        $select = $this->createStub(Select::class);
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        return $collection;
    }

    private function order(int $id = 1, string $increment = '100000001'): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($increment);
        $order->method('getState')->willReturnCallback(fn () => $this->state);
        $order->method('canCancel')->willReturn(true);
        $order->method('registerCancellation')->willReturnCallback(function () use ($order) {
            $this->steps[] = 'cancel';
            $this->state = Order::STATE_CANCELED;

            return $order;
        });

        return $order;
    }
}
