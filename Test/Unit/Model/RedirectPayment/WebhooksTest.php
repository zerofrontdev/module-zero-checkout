<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\Config;
use Zero\Checkout\Model\RedirectPayment\LatePaymentInterface;
use Zero\Checkout\Model\RedirectPayment\NoWebhookHandlerException;
use Zero\Checkout\Model\RedirectPayment\OrderLockedException;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\WebhookHandlerInterface;
use Zero\Checkout\Model\RedirectPayment\Webhooks;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * A verified webhook: handled now by the method's handler, under the order's
 * lock and on the reloaded order, or published for the consumer when Process
 * Through the Queue is on. A cancelled order is only asked about a late
 * payment (and flagged once) when its handler can tell.
 *
 *   controller -> Webhooks::handle -> settle -> handler(method) [no handler: throw, no lock]
 *              -> OrderLock(FakeLockManager) -> fresh order
 *              -> cancelled + LatePaymentInterface ? latePayment -> OrderPayment::paidAfterCancel
 *                                                  : handler->webhook(fresh)
 */
#[AllowMockObjectsWithoutExpectations]
class WebhooksTest extends TestCase
{
    use OrderLockBuilder;

    private bool $queued = false;
    private WebhookHandlerInterface&MockObject $handler;
    private PublisherInterface&MockObject $publisher;
    private OrderRepositoryInterface&MockObject $repository;
    private NotifierInterface&MockObject $notifier;
    private FakeLockManager $locks;
    /** @var array<int, Order> */
    private array $fresh = [];

    protected function setUp(): void
    {
        $this->handler = $this->createMock(WebhookHandlerInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->repository = $this->createMock(OrderRepositoryInterface::class);
        $this->notifier = $this->createMock(NotifierInterface::class);
        $this->locks = new FakeLockManager();
    }

    public function testHandledNowWhileTheProviderWaits(): void
    {
        $order = $this->order();
        $this->handler->expects($this->once())->method('webhook')->with($order, ['id' => 'tr_1']);
        $this->publisher->expects($this->never())->method('publish');
        $this->webhooks()->handle($order, ['id' => 'tr_1']);
    }

    public function testTheProviderNotAnsweringIsPassedOnWhenHandledNow(): void
    {
        $this->handler->method('webhook')->willThrowException(new LocalizedException(__('Mollie did not answer')));
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Mollie did not answer');
        $this->webhooks()->handle($this->order(), []);
    }

    public function testPublishedForTheConsumerWhenQueued(): void
    {
        $this->queued = true;
        $this->handler->expects($this->never())->method('webhook');
        $this->publisher->expects($this->once())->method('publish')->with(
            'zero.checkout.webhook',
            '{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":1}'
        );
        $this->webhooks()->handle($this->order(), ['id' => 'tr_1']);
        $this->assertSame([], $this->locks->calls);
    }

    public function testAMethodWithoutAHandler(): void
    {
        $this->expectException(NoWebhookHandlerException::class);
        $this->webhooks()->handle($this->order('zero_other'), []);
    }

    public function testHandledNowRunsUnderTheLockOnAFreshOrder(): void
    {
        $stale = $this->order();
        $this->fresh[12] = $fresh = $this->order();
        $this->handler->expects($this->once())->method('webhook')->with($fresh, ['id' => 'tr_1'])
            ->willReturnCallback(function (): void {
                $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000012'));
            });

        $this->webhooks()->settle($stale, 'zero_mollie', ['id' => 'tr_1']);

        $this->assertSame([['lock', 'zero_redirect_payment_100000012', 10]], $this->locks->locks());
        $this->assertSame([], $this->locks->held);
    }

    public function testCancelledOrderAsksLatePaymentNotWebhook(): void
    {
        $handler = $this->lateHandler();
        $order = $this->order('zero_mollie', Order::STATE_CANCELED);
        $handler->expects($this->once())->method('latePayment')->with($order)
            ->willReturn(['id' => 'tr_late', 'amount' => '10.00', 'currency' => 'EUR']);
        $handler->expects($this->never())->method('webhook');
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->stringContains('tr_late'));
        $this->repository->expects($this->once())->method('save')->with($order);
        $this->notifier->expects($this->once())->method('addMajor')
            ->with($this->stringContains('100000012'), 'zero_mollie 10.00 EUR paid');
        // AC-B3-2: a payment after the cancellation is flagged for a person, never invoiced or captured.
        $payment = $order->getPayment();
        $payment->expects($this->never())->method('registerCaptureNotification');
        $payment->expects($this->never())->method('capture');
        $payment->expects($this->never())->method('pay');
        $order->expects($this->never())->method('prepareInvoice');

        $this->webhooks($handler)->settle($order, 'zero_mollie', ['id' => 'tr_late']);

        $this->assertSame('tr_late', $order->getPayment()->getAdditionalInformation(OrderPayment::LATE_MARKER));
    }

    public function testCancelledOrderWithNothingHeldIsLeftAlone(): void
    {
        $handler = $this->lateHandler();
        $handler->method('latePayment')->willReturn(null);
        $handler->expects($this->never())->method('webhook');
        $this->repository->expects($this->never())->method('save');
        $this->notifier->expects($this->never())->method('addMajor');

        $this->webhooks($handler)->settle($this->order('zero_mollie', Order::STATE_CANCELED), 'zero_mollie', []);
    }

    public function testCancelledOrderWithoutLatePaymentSupportStillReachesWebhook(): void
    {
        $order = $this->order('zero_mollie', Order::STATE_CANCELED);
        $this->handler->expects($this->once())->method('webhook')->with($order, ['id' => 'tr_1']);
        $this->notifier->expects($this->never())->method('addMajor');

        $this->webhooks()->settle($order, 'zero_mollie', ['id' => 'tr_1']);
    }

    public function testAPendingOrderGoesToWebhookEvenWithLatePaymentSupport(): void
    {
        $handler = $this->lateHandler();
        $order = $this->order();
        $handler->expects($this->never())->method('latePayment');
        $handler->expects($this->once())->method('webhook')->with($order, []);

        $this->webhooks($handler)->settle($order, 'zero_mollie', []);
    }

    public function testLatePaymentFlagsOnce(): void
    {
        $handler = $this->lateHandler();
        $order = $this->order('zero_mollie', Order::STATE_CANCELED);
        $handler->expects($this->exactly(2))->method('latePayment')
            ->willReturn(['id' => 'tr_late', 'amount' => '10.00', 'currency' => 'EUR']);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->repository->expects($this->once())->method('save');
        $this->notifier->expects($this->once())->method('addMajor');
        $webhooks = $this->webhooks($handler);

        $webhooks->settle($order, 'zero_mollie', []);
        $webhooks->settle($order, 'zero_mollie', []);
    }

    public function testNoHandlerThrowsBeforeLocking(): void
    {
        try {
            $this->webhooks()->settle($this->order('zero_other'), 'zero_other', []);
            $this->fail('NoWebhookHandlerException expected');
        } catch (NoWebhookHandlerException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        }
        $this->assertCount(0, $this->locks->locks());
    }

    public function testLockHeldGives503Contract(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000012');
        $this->handler->expects($this->never())->method('webhook');

        $this->expectException(OrderLockedException::class);
        $this->webhooks()->handle($this->order(), []);
    }

    private function webhooks(?WebhookHandlerInterface $handler = null): Webhooks
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path) => $path === Config::XML_PATH_WEBHOOK_QUEUE && $this->queued
        );
        $orderPayment = new OrderPayment(
            $this->repository,
            $this->createStub(OrderSender::class),
            $this->notifier,
            $this->createStub(LoggerInterface::class)
        );

        return new Webhooks(
            new Config($scopeConfig),
            $this->publisher,
            $this->orderLock($this->locks, $this->fresh),
            $orderPayment,
            ['zero_mollie' => $handler ?? $this->handler]
        );
    }

    private function lateHandler(): WebhookHandlerInterface&LatePaymentInterface&MockObject
    {
        return $this->createMockForIntersectionOfInterfaces([WebhookHandlerInterface::class, LatePaymentInterface::class]);
    }

    private function order(string $method = 'zero_mollie', string $state = Order::STATE_PENDING_PAYMENT): Order&MockObject
    {
        $info = [];
        // A mock, so a test can assert what is never called on it (registerCaptureNotification, capture).
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
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
        $order->method('getId')->willReturn(12);
        $order->method('getIncrementId')->willReturn('100000012');
        $order->method('getState')->willReturn($state);

        return $order;
    }
}
