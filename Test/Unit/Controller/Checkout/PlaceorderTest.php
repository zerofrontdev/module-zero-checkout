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
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
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
    private ?LoggerInterface $logger = null;

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
        ]);
        $quote->method('getId')->willReturn(9);
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
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

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
            $this->validators,
            $this->afterPlaceOrder
        ))->execute();
    }
}
