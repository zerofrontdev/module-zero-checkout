<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Where the customer goes back to: a failed or abandoned payment restores
 * the cart (zero_checkout_quote_restored only when Magento really restored
 * it, with the order read before restoreQuote() forgets it); the rest goes to
 * the success page.
 */
#[AllowMockObjectsWithoutExpectations]
class ReturnResultTest extends TestCase
{
    private CheckoutSession&MockObject $session;
    private MessageManager&MockObject $messages;
    private EventManager&MockObject $events;
    private Order $order;
    private Quote $quote;
    private string $path = '';
    /** @var list<string> */
    private array $steps = [];
    private bool $restores = true;

    protected function setUp(): void
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn('zero_pay');
        $this->order = $this->createStub(Order::class);
        $this->order->method('getId')->willReturn(12);
        $this->order->method('getIncrementId')->willReturn('100000012');
        $this->order->method('getPayment')->willReturn($payment);
        $this->quote = $this->createStub(Quote::class);
        $this->session = $this->createMock(CheckoutSession::class);
        $this->session->method('getLastRealOrder')->willReturnCallback(function (): Order {
            $this->steps[] = 'lastOrder';

            return $this->order;
        });
        $this->session->method('restoreQuote')->willReturnCallback(function (): bool {
            $this->steps[] = 'restore';

            return $this->restores;
        });
        $this->session->method('getQuote')->willReturn($this->quote);
        $this->messages = $this->createMock(MessageManager::class);
        $this->events = $this->createMock(EventManager::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function restoringStates(): array
    {
        return ['failed' => [ReturnResult::FAILED], 'not started' => [ReturnResult::NOT_STARTED]];
    }

    #[DataProvider('restoringStates')]
    public function testARealRestoreDispatchesTheEvent(string $state): void
    {
        $this->events->expects($this->once())->method('dispatch')->with(
            ReturnResult::QUOTE_RESTORED_EVENT,
            ['order' => $this->order, 'quote' => $this->quote]
        );
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->returnResult()->redirect($state, 'Mollie');

        $this->assertSame('zero/checkout/index', $this->path);
        $this->assertSame(['lastOrder', 'restore'], $this->steps);
    }

    public function testNoEventWhenNothingWasRestored(): void
    {
        $this->restores = false;
        $this->events->expects($this->never())->method('dispatch');

        $this->returnResult()->redirect(ReturnResult::FAILED, 'Mollie');

        $this->assertSame('zero/checkout/index', $this->path);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function successStates(): array
    {
        return [
            'paid' => [ReturnResult::PAID, false],
            'pending' => [ReturnResult::PENDING, true],
            'unconfirmed' => [ReturnResult::UNCONFIRMED, true],
        ];
    }

    #[DataProvider('successStates')]
    public function testOtherStatesGoToTheSuccessPageWithTheCartClosed(string $state, bool $notice): void
    {
        $this->session->expects($this->never())->method('restoreQuote');
        $this->events->expects($this->never())->method('dispatch');
        $this->messages->expects($notice ? $this->once() : $this->never())->method('addNoticeMessage');

        $this->returnResult()->redirect($state, 'Mollie');

        $this->assertSame('checkout/onepage/success', $this->path);
    }

    public function testLastOrderOnlyWhenItIsTheNamedOneOfThisMethod(): void
    {
        $result = $this->returnResult();

        $this->assertSame($this->order, $result->lastOrder('zero_pay', '100000012'));
        $this->assertNull($result->lastOrder('zero_pay', '100000013'));
        $this->assertNull($result->lastOrder('zero_other', '100000012'));
        $this->assertNull($result->lastOrder('zero_pay', ''));
    }

    private function returnResult(): ReturnResult
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect): Redirect {
            $this->path = $path;

            return $redirect;
        });
        $factory = $this->createStub(ResultFactory::class);
        $factory->method('create')->willReturn($redirect);

        return new ReturnResult($this->session, $factory, $this->messages, $this->events);
    }
}
