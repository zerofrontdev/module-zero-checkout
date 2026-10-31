<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;

/**
 * A payment for an order already cancelled is flagged once (comment, error
 * log, admin notice), never booked. An order that waited too long is
 * cancelled only after the provider was asked once more and still reports
 * nothing; the result says whether it really is cancelled.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderPaymentTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $repository;
    private NotifierInterface&MockObject $notifier;
    private LoggerInterface&MockObject $logger;
    private OrderPayment $orderPayment;
    private string $state = Order::STATE_PENDING_PAYMENT;
    private bool $canCancel = true;
    /** @var list<string> */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrderRepositoryInterface::class);
        $this->notifier = $this->createMock(NotifierInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orderPayment = new OrderPayment(
            $this->repository,
            $this->createStub(OrderSender::class),
            $this->notifier,
            $this->logger
        );
    }

    public function testPaidAfterCancelCommentsNotifiesAndMarksOnce(): void
    {
        $this->state = Order::STATE_CANCELED;
        $order = $this->order();
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->logicalAnd($this->stringContains('tr_late'), $this->stringContains('refund')));
        $this->repository->expects($this->once())->method('save')->with($order);
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('paid after cancel'));
        $this->notifier->expects($this->once())->method('addMajor')
            ->with('Payment received for cancelled order #100000001', 'zero_pay 10.00 EUR paid');

        $this->assertTrue($this->orderPayment->paidAfterCancel($order, 'tr_late', 'zero_pay 10.00 EUR paid'));
        $this->assertFalse($this->orderPayment->paidAfterCancel($order, 'tr_late', 'zero_pay 10.00 EUR paid'));
        $this->assertSame('tr_late', $order->getPayment()->getAdditionalInformation(OrderPayment::LATE_MARKER));
    }

    /**
     * R8-11: a held payment (AUTHORIZED, PREAUTH, LOCKED…) is released, not
     * refunded, so the comment names both, with the provider's transaction.
     */
    public function testPaidAfterCancelCommentSaysRefundOrRelease(): void
    {
        $this->state = Order::STATE_CANCELED;
        $order = $this->order();
        $order->expects($this->once())->method('addCommentToStatusHistory')->with(
            'Payment tr_held was received after this order was cancelled. The order stays cancelled: '
            . 'refund or release it in the provider\'s account.'
        );

        $this->assertTrue($this->orderPayment->paidAfterCancel($order, 'tr_held', 'zero_pay 10.00 EUR paid'));
    }

    public function testPaidAfterCancelIgnoresAnOrderNotCancelled(): void
    {
        foreach ([Order::STATE_PENDING_PAYMENT, Order::STATE_PROCESSING, Order::STATE_COMPLETE] as $state) {
            $this->state = $state;
            $order = $this->order();
            $order->expects($this->never())->method('addCommentToStatusHistory');
            $this->assertFalse($this->orderPayment->paidAfterCancel($order, 'tr_1', 'details'), $state);
            $this->assertNull($order->getPayment()->getAdditionalInformation(OrderPayment::LATE_MARKER));
        }
        $this->repository->expects($this->never())->method('save');
        $this->notifier->expects($this->never())->method('addMajor');
    }

    public function testCancelUnlessPaidAsksFirst(): void
    {
        $order = $this->order();
        $order->expects($this->once())->method('registerCancellation')->with('gave up');
        $this->repository->expects($this->once())->method('save')->with($order);

        $cancelled = $this->orderPayment->cancelUnlessPaid($order, 'gave up', function (Order $asked) use ($order): void {
            $this->assertSame($order, $asked);
            $this->steps[] = 'ask';
        });

        $this->assertTrue($cancelled);
        $this->assertSame(['ask', 'cancel'], $this->steps);
    }

    public function testCancelUnlessPaidKeepsTheOrderWhenTheAskThrows(): void
    {
        $order = $this->order();
        $order->expects($this->never())->method('registerCancellation');
        $this->repository->expects($this->never())->method('save');
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('Provider down'));

        $cancelled = $this->orderPayment->cancelUnlessPaid($order, 'gave up', static function (): void {
            throw new LocalizedException(__('Provider down'));
        });

        $this->assertFalse($cancelled);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->state);
    }

    public function testCancelUnlessPaidKeepsAnOrderTheAskPaid(): void
    {
        $order = $this->order();
        $order->expects($this->never())->method('registerCancellation');

        $cancelled = $this->orderPayment->cancelUnlessPaid($order, 'gave up', function (): void {
            $this->state = Order::STATE_PROCESSING;
        });

        $this->assertFalse($cancelled);
    }

    public function testCancelUnlessPaidDoesNotAskAboutASettledOrder(): void
    {
        $this->state = Order::STATE_PROCESSING;
        $asked = false;

        $cancelled = $this->orderPayment->cancelUnlessPaid($this->order(), 'gave up', static function () use (&$asked): void {
            $asked = true;
        });

        $this->assertFalse($cancelled);
        $this->assertFalse($asked);
    }

    public function testCancelUnlessPaidReturnsTheRealStateWhenTheOrderCannotBeCancelled(): void
    {
        $this->canCancel = false;
        $order = $this->order();
        $order->expects($this->never())->method('registerCancellation');
        $this->repository->expects($this->never())->method('save');

        $this->assertFalse($this->orderPayment->cancelUnlessPaid($order, 'gave up', static fn () => null));
    }

    private function order(): Order&MockObject
    {
        $info = [];
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static function ($key = null) use (&$info) {
                return $key === null ? $info : ($info[$key] ?? null);
            }
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            static function ($key, $value = null) use (&$info, &$payment) {
                $info[$key] = $value;

                return $payment;
            }
        );
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getState')->willReturnCallback(fn () => $this->state);
        $order->method('canCancel')->willReturnCallback(fn () => $this->canCancel);
        $order->method('registerCancellation')->willReturnCallback(function () use ($order) {
            $this->steps[] = 'cancel';
            $this->state = Order::STATE_CANCELED;

            return $order;
        });

        return $order;
    }
}
