<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * The customer back from the provider: the updater runs under the order's
 * lock on the reloaded order, and every outcome becomes a ReturnResult state.
 *
 *   caller -> ReturnFlow -> OrderLock(FakeLockManager) -> collection: fresh order
 *                        -> $update(fresh) -> state [-> $onNotStarted(fresh)]
 */
#[AllowMockObjectsWithoutExpectations]
class ReturnFlowTest extends TestCase
{
    use OrderLockBuilder;

    private FakeLockManager $locks;
    private LoggerInterface&MockObject $logger;
    private Order $stale;
    private Order $fresh;

    protected function setUp(): void
    {
        $this->locks = new FakeLockManager();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->stale = $this->order(Order::STATE_PENDING_PAYMENT);
        $this->fresh = $this->order(Order::STATE_PENDING_PAYMENT);
    }

    public function testStateFromTheUpdater(): void
    {
        $seen = [];
        $state = $this->flow()->run($this->stale, function (Order $order) use (&$seen): string {
            $seen[] = $order;

            return ReturnResult::PAID;
        });

        $this->assertSame(ReturnResult::PAID, $state);
        $this->assertSame([$this->fresh], $seen);
        $this->assertSame([['lock', 'zero_redirect_payment_100000001', 10]], $this->locks->locks(), 'the return waits up to 10 s');
        $this->assertSame([], $this->locks->held);
    }

    public function testOnNotStartedThatCannotReachTheProviderIsUnconfirmed(): void
    {
        // Spec step 5 ("from 3 or 4"): making the link unpayable failed, so the order's state is not known.
        $this->logger->expects($this->never())->method('error');

        $state = $this->flow()->run(
            $this->stale,
            static fn () => ReturnResult::NOT_STARTED,
            static function (): void {
                throw new LocalizedException(__('The provider did not answer.'));
            }
        );

        $this->assertSame(ReturnResult::UNCONFIRMED, $state);
        $this->assertSame([], $this->locks->held);
    }

    public function testNotStartedRunsOnNotStarted(): void
    {
        $abandoned = [];
        $state = $this->flow()->run(
            $this->stale,
            static fn () => ReturnResult::NOT_STARTED,
            function (Order $order) use (&$abandoned): void {
                $abandoned[] = $order;
            }
        );

        $this->assertSame(ReturnResult::NOT_STARTED, $state);
        $this->assertSame([$this->fresh], $abandoned);
    }

    public function testOnNotStartedNotRunForOtherStates(): void
    {
        $calls = 0;
        $onNotStarted = static function () use (&$calls): void {
            $calls++;
        };
        foreach ([ReturnResult::PAID, ReturnResult::PENDING, ReturnResult::FAILED, ReturnResult::UNCONFIRMED] as $expected) {
            $this->assertSame($expected, $this->flow()->run($this->stale, static fn () => $expected, $onNotStarted));
        }

        $this->assertSame(0, $calls);
    }

    public function testProviderDownIsUnconfirmed(): void
    {
        $this->logger->expects($this->never())->method('error');

        $state = $this->flow()->run($this->stale, static function (): string {
            throw new LocalizedException(__('The provider did not answer.'));
        });

        $this->assertSame(ReturnResult::UNCONFIRMED, $state);
        $this->assertSame([], $this->locks->held);
    }

    public function testUnexpectedErrorIsUnconfirmedAndLogged(): void
    {
        $this->logger->expects($this->once())->method('error')->with(
            $this->logicalAnd($this->stringContains('100000001'), $this->stringContains('TypeError'), $this->stringContains('bad'))
        );

        $state = $this->flow()->run($this->stale, static function (): string {
            throw new \TypeError('bad');
        });

        $this->assertSame(ReturnResult::UNCONFIRMED, $state);
        $this->assertSame([], $this->locks->held);
    }

    public function testLockHeldIsPending(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000001');
        $this->logger->expects($this->never())->method('error');
        $asked = false;

        $state = $this->flow()->run($this->stale, static function () use (&$asked): string {
            $asked = true;

            return ReturnResult::PAID;
        });

        $this->assertSame(ReturnResult::PENDING, $state);
        $this->assertFalse($asked);
    }

    public function testCancelledAndPaidAfterCancelIsPendingWithoutAskingTheProvider(): void
    {
        $this->fresh = $this->order(Order::STATE_CANCELED, 'tr_late');
        $asked = false;

        $state = $this->flow()->run($this->stale, static function () use (&$asked): string {
            $asked = true;

            return ReturnResult::FAILED;
        });

        $this->assertSame(ReturnResult::PENDING, $state);
        $this->assertFalse($asked);
    }

    public function testCancelledWithoutALatePaymentStillAsksTheProvider(): void
    {
        $this->fresh = $this->order(Order::STATE_CANCELED);

        $state = $this->flow()->run($this->stale, static fn () => ReturnResult::FAILED);

        $this->assertSame(ReturnResult::FAILED, $state);
    }

    private function flow(): ReturnFlow
    {
        return new ReturnFlow($this->orderLock($this->locks, [12 => $this->fresh]), $this->logger);
    }

    private function order(string $state, ?string $lateMarker = null): Order
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn ($key = null) => $key === OrderPayment::LATE_MARKER ? $lateMarker : null
        );
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(12);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}
