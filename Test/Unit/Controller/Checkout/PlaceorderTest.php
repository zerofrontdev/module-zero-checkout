<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Api\AfterPlaceOrderInterface;
use Zero\Checkout\Api\PlaceOrderValidatorInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;
use Zero\Checkout\Model\SupportedPaymentMethods;

#[AllowMockObjectsWithoutExpectations]
class PlaceorderTest extends TestCase
{
    use MockCreationTrait;

    private const VALID = [
        'shipping_method' => 'flatrate_flatrate',
        'payment_method' => 'checkmo',
        'firstname' => 'Ann',
    ];

    private ?string $redirectPath = null;
    /** @var string[] */
    private array $errors = [];
    private bool $formKeyValid = true;
    private ?string $paypalRedirectUrl = null;
    private ?Rate $rate = null;
    private CartManagementInterface&MockObject $cartManagement;
    private QuotePayment&MockObject $quotePayment;
    /** @var PlaceOrderValidatorInterface[] */
    private array $validators = [];
    /** @var AfterPlaceOrderInterface[] */
    private array $afterPlaceOrder = [];
    private ?Quote $quote = null;
    private ?LoggerInterface $logger = null;
    private ?EventManager $eventManager = null;
    private ?OrderStatusHistoryRepositoryInterface $historyRepository = null;
    private ?OrderStatusHistoryInterface $history = null;
    private ?\Throwable $orderLoadFailure = null;
    private ?OrderRepositoryInterface $orderRepository = null;

    protected function setUp(): void
    {
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartManagement->method('placeOrder')->willReturn(77);
        $this->quotePayment = $this->createMock(QuotePayment::class);
        // Data-only rate: carrier_title/method_title are magic getters.
        $this->rate = $this->createPartialMock(Rate::class, ['save']);
        $this->rate->setData(['carrier_title' => 'Flat Rate', 'method_title' => 'Fixed']);
    }

    public function testValidOrderGoesToSuccessPage(): void
    {
        $this->cartManagement->expects($this->once())->method('placeOrder')->with(9);

        $this->submit(self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
        $this->assertSame([], $this->errors);
    }

    public function testValidatorCanStopTheOrderWithItsMessage(): void
    {
        $validator = $this->createMock(PlaceOrderValidatorInterface::class);
        $validator->expects($this->once())->method('validate')
            ->with($this->anything(), $this->callback(fn (array $request) => $request['firstname'] === 'Ann'))
            ->willThrowException(new LocalizedException(__('Tick the box.')));
        $this->validators = ['terms' => $validator];
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(self::VALID);

        $this->assertSame('zero/checkout/index', $this->redirectPath);
        $this->assertSame(['Tick the box.'], $this->errors);
    }

    public function testFailingAfterPlaceHandlerIsLoggedAndTheOrderStillSucceeds(): void
    {
        $handler = $this->createMock(AfterPlaceOrderInterface::class);
        $handler->expects($this->once())->method('execute')->willThrowException(new \RuntimeException('boom'));
        $this->afterPlaceOrder = ['note' => $handler];
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('"note"'));

        $this->submit(self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
        $this->assertSame([], $this->errors);
    }

    public function testPaypalOrderGoesToPaypalRedirect(): void
    {
        $this->paypalRedirectUrl = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_xclick';

        $this->submit(['payment_method' => 'zero_paypal_standard'] + self::VALID);

        $this->assertSame('zero_paypal/standard/redirect', $this->redirectPath);
    }

    public function testInvalidFormKeyPlacesNothing(): void
    {
        $this->formKeyValid = false;
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(self::VALID);

        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testMissingShippingMethodPlacesNothing(): void
    {
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(['shipping_method' => ''] + self::VALID);

        $this->assertSame(['Please select a shipping method.'], $this->errors);
    }

    public function testUnsupportedPaymentMethodPlacesNothing(): void
    {
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(['payment_method' => 'paypal_express'] + self::VALID);

        $this->assertSame(['Please select a payment method.'], $this->errors);
    }

    public function testUnavailableShippingRateIsReported(): void
    {
        $this->rate = null;
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(self::VALID);

        $this->assertSame(['The selected shipping method is no longer available for this address.'], $this->errors);
        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testPaymentTokensArePassedInAdditionalDataAndPoNumberAtTopLevel(): void
    {
        $this->quotePayment->expects($this->once())->method('importData')->with([
            'method' => 'purchaseorder',
            'additional_data' => [
                'payment_method_nonce' => 'nonce-1',
                'stripe_payment_intent_id' => 'pi_1',
                'authnet_data_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
                'authnet_data_value' => 'opaque',
            ],
            'po_number' => 'PO-42',
        ]);

        $this->submit([
            'payment_method' => 'purchaseorder',
            'payment_method_nonce' => 'nonce-1',
            'stripe_payment_intent_id' => 'pi_1',
            'authnet_data_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'authnet_data_value' => 'opaque',
            'po_number' => 'PO-42',
        ] + self::VALID);
    }

    public function testOtherMethodsPassTheirFieldsThroughPaymentAdditionalData(): void
    {
        $this->quotePayment->expects($this->once())->method('importData')->with([
            'method' => 'checkmo',
            'additional_data' => ['acme_token' => 'tok_1'],
        ]);

        $this->submit([
            'payment_additional_data' => ['acme_token' => 'tok_1', 'nested' => ['x']],
        ] + self::VALID);
    }

    public function testAnArrayShippingMethodIsRefusedLikeAMissingOne(): void
    {
        $this->cartManagement->expects($this->never())->method('placeOrder');

        // shipping_method[]=x used to raise "Array to string conversion" outside the try: a 500.
        $this->submit(['shipping_method' => ['x']] + self::VALID);

        $this->assertSame(['Please select a shipping method.'], $this->errors);
        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testAnArrayPaymentMethodIsRefusedLikeAnUnsupportedOne(): void
    {
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->submit(['payment_method' => ['checkmo']] + self::VALID);

        $this->assertSame(['Please select a payment method.'], $this->errors);
    }

    public function testArrayPaymentTokensAndPoNumberAreLeftOut(): void
    {
        $this->quotePayment->expects($this->once())->method('importData')->with([
            'method' => 'checkmo',
            'additional_data' => ['stripe_payment_intent_id' => 'pi_1'],
        ]);

        $this->submit([
            'payment_method_nonce' => ['x'],
            'stripe_payment_intent_id' => 'pi_1',
            'authnet_data_descriptor' => ['x'],
            'authnet_data_value' => [['x']],
            'po_number' => ['PO-42'],
        ] + self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
    }

    public function testPaymentFailureIsShownToCustomer(): void
    {
        $this->cartManagement->method('placeOrder')
            ->willThrowException(new LocalizedException(__('Your card payment was not completed.')));

        $this->submit(['payment_method' => 'zero_stripe'] + self::VALID);

        $this->assertSame(['Your card payment was not completed.'], $this->errors);
        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testUnexpectedErrorIsShownGenerically(): void
    {
        $this->cartManagement->method('placeOrder')->willThrowException(new \RuntimeException('SQL error'));

        $this->submit(self::VALID);

        $this->assertSame(['We can\'t place the order right now. Please try again.'], $this->errors);
    }

    public function testValidationFailureDispatchesTheFailureEvent(): void
    {
        $failure = new LocalizedException(__('Your card payment was not completed.'));
        $this->cartManagement->method('placeOrder')->willThrowException($failure);
        $events = [];
        $this->eventManager = $this->recordingEvents($events);

        $this->submit(['payment_method' => 'zero_stripe'] + self::VALID);

        $this->assertCount(1, $events);
        [$name, $data] = $events[0];
        $this->assertSame('zero_checkout_place_order_failed', $name);
        $this->assertSame($this->quote, $data['quote']);
        $this->assertSame('zero_stripe', $data['request']['payment_method']);
        $this->assertSame($failure, $data['exception']);
        $this->assertNull($data['order_id']);
        $this->assertSame(['Your card payment was not completed.'], $this->errors);
    }

    public function testGenericFailureIsLoggedWithIdsAndDispatched(): void
    {
        $failure = new \RuntimeException('SQL error');
        $this->cartManagement->method('placeOrder')->willThrowException($failure);
        $this->quotePayment->method('getMethod')->willReturn('zero_stripe');
        $this->quotePayment->method('getAdditionalInformation')->willReturnMap([['stripe_payment_intent_id', 'pi_1']]);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('error')->with('Zero Checkout: place order failed', [
            'quote_id' => 9,
            'reserved_order_id' => '000000077',
            'payment_method' => 'zero_stripe',
            'stripe_payment_intent_id' => 'pi_1',
            'exception' => $failure,
        ]);
        $events = [];
        $this->eventManager = $this->recordingEvents($events);

        $this->submit(['payment_method' => 'zero_stripe'] + self::VALID);

        $this->assertSame('zero_checkout_place_order_failed', $events[0][0] ?? null);
        $this->assertSame($failure, $events[0][1]['exception']);
        $this->assertSame(['We can\'t place the order right now. Please try again.'], $this->errors);
    }

    /**
     * The order was saved, then a later step threw: the event says which order, so nothing releases its payment.
     */
    public function testFailureAfterTheOrderWasSavedCarriesItsId(): void
    {
        $this->orderLoadFailure = new \RuntimeException('deadlock');
        $events = [];
        $this->eventManager = $this->recordingEvents($events);

        $this->submit(self::VALID);

        $this->assertSame(77, $events[0][1]['order_id']);
        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testFailingFailureObserverIsLoggedAndTheCustomerStillGetsTheMessage(): void
    {
        $this->cartManagement->method('placeOrder')
            ->willThrowException(new LocalizedException(__('Your card payment was not completed.')));
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('observer'));
        $this->eventManager = $eventManager;
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('error')
            ->with('Zero Checkout: zero_checkout_place_order_failed observer failed: observer');

        $this->submit(self::VALID);

        $this->assertSame(['Your card payment was not completed.'], $this->errors);
        $this->assertSame('zero/checkout/index', $this->redirectPath);
    }

    public function testSuccessDispatchesNoFailureEvent(): void
    {
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->never())->method('dispatch');
        $this->eventManager = $eventManager;

        $this->submit(self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
    }

    public function testAfterPlaceFailureAddsOrderComment(): void
    {
        $handler = $this->createStub(AfterPlaceOrderInterface::class);
        $handler->method('execute')->willThrowException(new \RuntimeException('Could not save an invoice'));
        $this->afterPlaceOrder = ['stripe_capture' => $handler];
        $history = $this->createMock(OrderStatusHistoryInterface::class);
        $history->expects($this->once())->method('setParentId')->with(77)->willReturnSelf();
        $history->expects($this->once())->method('setEntityName')->with('order')->willReturnSelf();
        $history->expects($this->once())->method('setStatus')->with('processing')->willReturnSelf();
        $history->expects($this->once())->method('setIsCustomerNotified')->with(0)->willReturnSelf();
        $history->expects($this->once())->method('setIsVisibleOnFront')->with(0)->willReturnSelf();
        $history->expects($this->once())->method('setComment')->with(
            'After-order step "stripe_capture" failed: Could not save an invoice (details in var/log/exception.log)'
        )->willReturnSelf();
        $this->history = $history;
        $historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $historyRepository->expects($this->once())->method('save')->with($history)->willReturn($history);
        $this->historyRepository = $historyRepository;
        // The order itself is never saved: a failed capture can leave it changed in memory.
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');
        $this->orderRepository = $orderRepository;

        $this->submit(self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
        $this->assertSame([], $this->errors);
    }

    public function testCommentThatCannotBeSavedIsOnlyLogged(): void
    {
        $handler = $this->createStub(AfterPlaceOrderInterface::class);
        $handler->method('execute')->willThrowException(new \RuntimeException('boom'));
        $this->afterPlaceOrder = ['stripe_capture' => $handler];
        $historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $historyRepository->expects($this->once())->method('save')->willThrowException(new \RuntimeException('db gone'));
        $this->historyRepository = $historyRepository;
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) use (&$logged) {
            $logged[] = (string) $message;
        });
        $this->logger = $logger;

        $this->submit(self::VALID);

        $this->assertSame('checkout/onepage/success', $this->redirectPath);
        $this->assertSame([
            'Zero Checkout after-place-order handler "stripe_capture" failed for order 000000077: boom',
            'Zero Checkout: comment on order 000000077 for the failed step "stripe_capture" not saved: db gone',
        ], $logged);
    }

    /**
     * @param list<array{string, array<string, mixed>}> $events
     */
    private function recordingEvents(array &$events): EventManager
    {
        $eventManager = $this->createStub(EventManager::class);
        $eventManager->method('dispatch')->willReturnCallback(function (string $name, array $data = []) use (&$events) {
            $events[] = [$name, $data];
        });

        return $eventManager;
    }

    /**
     * @param array<string, string> $params
     */
    private function submit(array $params): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParams')->willReturn($params);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);

        $formKeyValidator = $this->createStub(FormKeyValidator::class);
        $formKeyValidator->method('validate')->willReturn($this->formKeyValid);

        $messages = $this->createStub(MessageManager::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string) $message;
            return $messages;
        });

        $address = $this->createPartialMock(Address::class, ['getShippingRateByCode']);
        $address->method('getShippingRateByCode')->willReturn($this->rate);
        $quote = $this->createPartialMockWithReflection(Quote::class, [
            'getId', 'getShippingAddress', 'getPayment', 'collectTotals', 'setTotalsCollectedFlag', 'getBaseGrandTotal', 'isVirtual',
            'getReservedOrderId',
        ]);
        $this->quote = $quote;
        $quote->method('getId')->willReturn(9);
        $quote->method('getReservedOrderId')->willReturn('000000077');
        $quote->method('isVirtual')->willReturn(false);
        // Something to pay (nothing would place it with "free", Zero_GiftCard's case).
        $quote->method('getBaseGrandTotal')->willReturn(42.0);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getPayment')->willReturn($this->quotePayment);
        $quote->method('setTotalsCollectedFlag')->willReturnSelf();

        $session = $this->createPartialMockWithReflection(CheckoutSession::class, [
            'getQuote', 'setLastQuoteId', 'setLastSuccessQuoteId', 'setLastOrderId',
            'setLastRealOrderId', 'setLastOrderStatus',
        ]);
        $session->method('getQuote')->willReturn($quote);
        foreach (['setLastQuoteId', 'setLastSuccessQuoteId', 'setLastOrderId', 'setLastRealOrderId', 'setLastOrderStatus'] as $setter) {
            $session->method($setter)->willReturnSelf();
        }

        $orderPayment = $this->createStub(OrderPayment::class);
        $orderPayment->method('getAdditionalInformation')->willReturnCallback(
            fn (string $key) => $key === 'paypal_redirect_url' ? $this->paypalRedirectUrl : null
        );
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('getEntityId')->willReturn(77);
        $order->method('getIncrementId')->willReturn('000000077');
        $order->method('getStatus')->willReturn('processing');
        $orderRepository = $this->orderRepository ?? $this->createStub(OrderRepositoryInterface::class);
        if ($this->orderLoadFailure !== null) {
            $orderRepository->method('get')->willThrowException($this->orderLoadFailure);
        } else {
            $orderRepository->method('get')->willReturn($order);
        }
        $historyFactory = $this->createStub(OrderStatusHistoryInterfaceFactory::class);
        $historyFactory->method('create')->willReturnCallback(
            fn () => $this->history ?? $this->createStub(OrderStatusHistoryInterface::class)
        );

        (new Placeorder(
            $request,
            $resultFactory,
            $session,
            $this->createStub(ShippingAddressUpdater::class),
            $this->cartManagement,
            $this->createStub(CartRepositoryInterface::class),
            $orderRepository,
            $messages,
            $formKeyValidator,
            $this->logger ?? $this->createStub(LoggerInterface::class),
            new SupportedPaymentMethods(),
            $this->eventManager ?? $this->createStub(EventManager::class),
            $this->historyRepository ?? $this->createStub(OrderStatusHistoryRepositoryInterface::class),
            $historyFactory,
            $this->validators,
            $this->afterPlaceOrder
        ))->execute();
    }
}
