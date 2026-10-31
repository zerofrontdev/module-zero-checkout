<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Cron\RecheckPending;
use Zero\Checkout\Model\Config;
use Zero\Checkout\Model\RedirectPayment\OrderUpdaterInterface;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Model\RedirectPayment\Updaters;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * Recheck Pending Payments (off by default): every 15 minutes, the
 * pending_payment orders 10 minutes to 7 hours old of the methods registered
 * in Updaters are asked about, each under its lock without waiting. A held
 * lock is skipped, one failure doesn't stop the run, and the cron itself
 * never cancels.
 *
 *   cron -> RecheckPending -> Config (on?) -> Updaters::all
 *        -> order collection (state, created_at window, p.method IN registered)
 *        -> per order: OrderLock(wait 0) -> updater->update(fresh, [])
 */
#[AllowMockObjectsWithoutExpectations]
class RecheckPendingTest extends TestCase
{
    use OrderLockBuilder;

    private bool $enabled = true;
    private FakeLockManager $locks;
    private LoggerInterface&MockObject $logger;
    private CollectionFactory&MockObject $factory;
    /** @var list<Order> */
    private array $found = [];
    /** @var list<array{0: string, 1: mixed}> */
    private array $filters = [];
    /** @var list<array{0: string, 1: mixed}> */
    private array $where = [];
    /** @var list<array{0: string, 1: string}> */
    private array $orderBy = [];
    private ?int $pageSize = null;

    protected function setUp(): void
    {
        $this->locks = new FakeLockManager();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->factory = $this->createMock(CollectionFactory::class);
        $this->factory->method('create')->willReturnCallback(fn () => $this->collection());
    }

    public function testOnlyRegisteredMethodsInTheWindow(): void
    {
        $before = time();
        $this->cron(['zero_a' => $this->updater(), 'zero_b' => $this->updater()])->execute();
        $after = time();

        $this->assertSame(['state', Order::STATE_PENDING_PAYMENT], $this->filters[0]);
        $this->assertSame('main_table.created_at', $this->filters[1][0]);
        $this->assertSame('main_table.created_at', $this->filters[2][0]);
        $this->assertWithin($this->filters[1][1]['lt'], $before - 600, $after - 600);
        $this->assertWithin($this->filters[2][1]['gt'], $before - 25200, $after - 25200);
        $this->assertSame([['p.method IN (?)', ['zero_a', 'zero_b']]], $this->where);
        $this->assertSame([['main_table.created_at', 'ASC']], $this->orderBy);
        $this->assertSame(200, $this->pageSize);
    }

    public function testEachOrderIsAskedUnderItsLockWithoutWaiting(): void
    {
        $order = $this->order(1, 'zero_a');
        $this->found = [$order];
        $updater = $this->updater();
        $updater->expects($this->once())->method('update')->with($order, [])
            ->willReturnCallback(function (): string {
                $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000001'));

                return ReturnResult::PAID;
            });

        $this->cron(['zero_a' => $updater])->execute();

        $this->assertSame([['lock', 'zero_redirect_payment_100000001', 0]], $this->locks->locks());
        $this->assertSame([], $this->locks->held);
    }

    public function testLockedOrderSkipped(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000001');
        $this->found = [$this->order(1, 'zero_a'), $this->order(2, 'zero_a')];
        $asked = [];
        $updater = $this->updater();
        $updater->method('update')->willReturnCallback(static function (Order $order) use (&$asked): string {
            $asked[] = $order->getIncrementId();

            return ReturnResult::PENDING;
        });
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->never())->method('info');

        $this->cron(['zero_a' => $updater])->execute();

        $this->assertSame(['100000002'], $asked);
    }

    public function testOneFailureDoesNotStopTheRun(): void
    {
        $this->found = [$this->order(1, 'zero_a'), $this->order(2, 'zero_a'), $this->order(3, 'zero_a')];
        $asked = [];
        $updater = $this->updater();
        $updater->method('update')->willReturnCallback(static function (Order $order) use (&$asked): string {
            $asked[] = $order->getIncrementId();
            return match ($order->getIncrementId()) {
                '100000001' => throw new \RuntimeException('broken'),
                '100000002' => throw new LocalizedException(__('provider down')),
                default => ReturnResult::PAID,
            };
        });
        $this->logger->expects($this->once())->method('error')
            ->with($this->logicalAnd($this->stringContains('100000001'), $this->stringContains('RuntimeException')));
        $this->logger->expects($this->once())->method('info')
            ->with($this->logicalAnd($this->stringContains('100000002'), $this->stringContains('provider down')));

        $this->cron(['zero_a' => $updater])->execute();

        $this->assertSame(['100000001', '100000002', '100000003'], $asked);
        $this->assertSame([], $this->locks->held);
    }

    public function testAMethodTheQueryShouldNotHaveReturnedIsSkipped(): void
    {
        $this->found = [$this->order(1, 'zero_other')];
        $updater = $this->updater();
        $updater->expects($this->never())->method('update');

        $this->cron(['zero_a' => $updater])->execute();

        $this->assertSame([], $this->locks->calls);
    }

    public function testOffDoesNothing(): void
    {
        $this->enabled = false;
        $updater = $this->updater();
        $updater->expects($this->never())->method('update');
        $this->factory->expects($this->never())->method('create');

        $this->cron(['zero_a' => $updater])->execute();
    }

    public function testNoRegisteredMethodDoesNothing(): void
    {
        $this->factory->expects($this->never())->method('create');

        $this->cron([])->execute();
    }

    public function testNeverCancelsByItself(): void
    {
        $orders = [$this->order(1, 'zero_a'), $this->order(2, 'zero_a'), $this->order(3, 'zero_a')];
        foreach ($orders as $order) {
            $order->expects($this->never())->method('cancel');
            $order->expects($this->never())->method('registerCancellation');
        }
        $this->found = $orders;
        $updater = $this->updater();
        $updater->method('update')->willReturnOnConsecutiveCalls(
            ReturnResult::NOT_STARTED,
            ReturnResult::UNCONFIRMED,
            ReturnResult::PENDING
        );

        $this->cron(['zero_a' => $updater])->execute();
    }

    /**
     * @param array<string, OrderUpdaterInterface> $updaters
     */
    private function cron(array $updaters): RecheckPending
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path) => $path === Config::XML_PATH_RECHECK_PENDING && $this->enabled
        );

        return new RecheckPending(
            new Updaters($updaters),
            $this->orderLock($this->locks),
            $this->factory,
            new Config($scopeConfig),
            $this->logger
        );
    }

    private function collection(): Collection
    {
        $select = $this->createStub(Select::class);
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->where[] = [$condition, $value];

            return $select;
        });
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition = null) use ($collection) {
            $this->filters[] = [$field, $condition];

            return $collection;
        });
        $collection->method('setOrder')->willReturnCallback(function ($field, $direction = 'DESC') use ($collection) {
            $this->orderBy[] = [$field, $direction];

            return $collection;
        });
        $collection->method('setPageSize')->willReturnCallback(function ($size) use ($collection) {
            $this->pageSize = $size;

            return $collection;
        });
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->found));

        return $collection;
    }

    private function updater(): OrderUpdaterInterface&MockObject
    {
        return $this->createMock(OrderUpdaterInterface::class);
    }

    private function order(int $id, string $method): Order&MockObject
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn(sprintf('10000000%d', $id));
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);

        return $order;
    }

    private function assertWithin(string $utc, int $from, int $to): void
    {
        $at = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertGreaterThanOrEqual($from, $at, $utc);
        $this->assertLessThanOrEqual($to, $at, $utc);
    }
}
