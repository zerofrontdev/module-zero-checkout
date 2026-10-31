<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\Config;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\WebhookConsumer;
use Zero\Checkout\Model\RedirectPayment\WebhookHandlerInterface;
use Zero\Checkout\Model\RedirectPayment\Webhooks;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;

/**
 * A queued webhook, from the consumer: handled for its order; tried again
 * after a pause when the provider can't be asked; after the last try, a
 * comment on the order. Only "no handler for the method" is not retried:
 * a provider error of any type and a lock held by another process are.
 *
 *   queue -> WebhookConsumer::process -> OrderRepository::get
 *         -> Webhooks::settle (OrderLock over FakeLockManager) -> handler->webhook
 *         -> on error: pause + publish(attempt + 1), or OrderPayment::flag after ATTEMPTS
 */
#[AllowMockObjectsWithoutExpectations]
class WebhookConsumerTest extends TestCase
{
    use OrderLockBuilder;

    private WebhookHandlerInterface&MockObject $handler;
    private PublisherInterface&MockObject $publisher;
    private OrderRepositoryInterface&MockObject $orders;
    private OrderPayment&MockObject $orderPayment;
    private Order&MockObject $order;
    private LoggerInterface&MockObject $logger;
    private FakeLockManager $locks;
    private WebhookConsumer $consumer;
    /** @var int[] */
    private array $pauses = [];

    protected function setUp(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $this->handler = $this->createMock(WebhookHandlerInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->order = $this->createMock(Order::class);
        $this->order->method('getId')->willReturn(12);
        $this->order->method('getIncrementId')->willReturn('100000012');
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->orders = $this->createMock(OrderRepositoryInterface::class);
        $this->orders->method('get')->willReturnCallback(function (int $id) {
            if ($id !== 12) {
                throw new NoSuchEntityException();
            }
            return $this->order;
        });
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->locks = new FakeLockManager();
        $this->consumer = new WebhookConsumer(
            new Webhooks(
                new Config($scopeConfig),
                $this->publisher,
                $this->orderLock($this->locks),
                $this->orderPayment,
                ['zero_mollie' => $this->handler]
            ),
            $this->orders,
            $this->orderPayment,
            $this->logger,
            function (int $seconds) {
                $this->pauses[] = $seconds;
            }
        );
    }

    public function testHandledForItsOrder(): void
    {
        $this->handler->expects($this->once())->method('webhook')->with($this->order, ['id' => 'tr_1']);
        $this->publisher->expects($this->never())->method('publish');
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":1}');
    }

    public function testTriedAgainAfterAPauseWhenTheProviderCannotBeAsked(): void
    {
        $this->handler->method('webhook')->willThrowException(new LocalizedException(__('Mollie did not answer')));
        $this->publisher->expects($this->once())->method('publish')->with(
            'zero.checkout.webhook',
            '{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":3}'
        );
        $this->orderPayment->expects($this->never())->method('flag');
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":2}');
        $this->assertSame([10], $this->pauses);
    }

    public function testAfterTheLastTryTheOrderGetsAComment(): void
    {
        $this->handler->method('webhook')->willThrowException(new LocalizedException(__('Mollie did not answer')));
        $this->publisher->expects($this->never())->method('publish');
        $this->orderPayment->expects($this->once())->method('flag')->with(
            $this->order,
            $this->stringContains('Mollie did not answer'),
            'webhook for zero_mollie not handled after 5 tries: Mollie did not answer'
        );
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{},"attempt":5}');
        $this->assertSame([], $this->pauses);
    }

    public function testAnOrderThatIsGoneOrAMethodWithoutAHandlerIsLogged(): void
    {
        $this->handler->expects($this->never())->method('webhook');
        $this->publisher->expects($this->never())->method('publish');
        $this->consumer->process('{"order_id":99,"method":"zero_mollie","data":{},"attempt":1}');
        $this->consumer->process('{"order_id":12,"method":"zero_other","data":{},"attempt":1}');
    }

    public function testInvalidArgumentFromTheProviderIsRetried(): void
    {
        $this->handler->method('webhook')->willThrowException(new \InvalidArgumentException('unexpected payload'));
        $this->publisher->expects($this->once())->method('publish')->with(
            'zero.checkout.webhook',
            '{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":2}'
        );
        $this->orderPayment->expects($this->never())->method('flag');
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":1}');
        $this->assertSame([5], $this->pauses);
    }

    public function testNoHandlerIsNotRetried(): void
    {
        $this->publisher->expects($this->never())->method('publish');
        $this->orderPayment->expects($this->never())->method('flag');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('zero_other'));
        $this->consumer->process('{"order_id":12,"method":"zero_other","data":{},"attempt":1}');
        $this->assertSame([], $this->pauses);
        $this->assertSame([], $this->locks->calls);
    }

    public function testLockHeldIsRetried(): void
    {
        $this->locks->holdByOther('zero_redirect_payment_100000012');
        $this->handler->expects($this->never())->method('webhook');
        $this->publisher->expects($this->once())->method('publish')->with(
            'zero.checkout.webhook',
            '{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":4}'
        );
        $this->orderPayment->expects($this->never())->method('flag');
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{"id":"tr_1"},"attempt":3}');
        $this->assertSame([15], $this->pauses);
    }

    public function testTheWebhookRunsUnderTheOrderLock(): void
    {
        $this->handler->expects($this->once())->method('webhook')->willReturnCallback(function (): void {
            $this->assertTrue($this->locks->isLocked('zero_redirect_payment_100000012'));
        });
        $this->consumer->process('{"order_id":12,"method":"zero_mollie","data":{},"attempt":1}');
        $this->assertSame([], $this->locks->held);
    }
}
