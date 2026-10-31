<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Registration;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CustomerAssignment;
use Magento\Sales\Model\Order\OrderCustomerExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Controller\Checkout\CreateAccount;

/**
 * A3-18: an account made on the success page signs the customer in, unless
 * the store requires email confirmation: then the order is still given to the
 * account, nobody is signed in, and the customer is sent to Sign In with a
 * "check your email" message (Magento's createAccount() sends the link).
 */
class CreateAccountTest extends TestCase
{
    use MockCreationTrait;

    private CustomerSession&MockObject $customerSession;
    private CustomerAssignment&MockObject $customerAssignment;
    private string $confirmationStatus = AccountManagementInterface::ACCOUNT_CONFIRMED;
    private bool $htmx = false;
    /** @var string[] */
    private array $successMessages = [];
    private ?string $redirectUrl = null;
    /** @var array<string, string> */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->customerAssignment = $this->createMock(CustomerAssignment::class);
    }

    public function testConfirmationRequiredDoesNotSignIn(): void
    {
        $this->confirmationStatus = AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED;
        $this->customerAssignment->expects($this->once())->method('execute');
        $this->customerSession->expects($this->never())->method('setCustomerDataAsLoggedIn');
        $this->customerSession->expects($this->never())->method('regenerateId');

        $this->controller()->execute();

        $this->assertSame(['Check ann@example.com for the confirmation link, then sign in.'], $this->successMessages);
        $this->assertSame('https://shop.test/customer/account/login', $this->redirectUrl);
    }

    public function testConfirmationRequiredOverHtmxRedirectsToSignIn(): void
    {
        $this->confirmationStatus = AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED;
        $this->htmx = true;
        $this->customerAssignment->expects($this->once())->method('execute');
        $this->customerSession->expects($this->never())->method('setCustomerDataAsLoggedIn');

        $this->controller()->execute();

        $this->assertSame(['HX-Redirect' => 'https://shop.test/customer/account/login'], $this->headers);
        $this->assertNull($this->redirectUrl);
    }

    public function testNoConfirmationSignsIn(): void
    {
        $this->customerAssignment->expects($this->once())->method('execute');
        $this->customerSession->expects($this->once())->method('setCustomerDataAsLoggedIn');
        $this->customerSession->expects($this->once())->method('regenerateId');

        $this->controller()->execute();

        $this->assertSame(['Your account is ready. Order #000000077 is in it.'], $this->successMessages);
        $this->assertSame('https://shop.test/sales/order/view?order_id=77', $this->redirectUrl);
    }

    private function controller(): CreateAccount
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'HX-Request' && $this->htmx);
        $request->method('getParam')->willReturnMap([
            ['password', null, 'Secret-123'],
            ['password_confirmation', null, 'Secret-123'],
        ]);

        $checkoutSession = $this->createPartialMockWithReflection(CheckoutSession::class, ['getLastOrderId']);
        $checkoutSession->expects($this->once())->method('getLastOrderId')->willReturn(77);
        $registration = $this->createStub(Registration::class);
        $registration->method('isAllowed')->willReturn(true);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getCustomerIsGuest')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn('ann@example.com');
        $order->method('getIncrementId')->willReturn('000000077');
        $order->method('getEntityId')->willReturn(77);
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getEmail')->willReturn('ann@example.com');
        $extractor = $this->createStub(OrderCustomerExtractor::class);
        $extractor->method('extract')->willReturn($customer);

        $accounts = $this->createStub(AccountManagementInterface::class);
        $accounts->method('isEmailAvailable')->willReturn(true);
        $accounts->method('createAccount')->willReturn($customer);
        $accounts->method('getConfirmationStatus')->willReturnCallback(
            fn (int $id) => $id === 5 ? $this->confirmationStatus : 'unknown'
        );

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setUrl')->willReturnCallback(function (string $url) use ($redirect) {
            $this->redirectUrl = $url;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $response = $this->createStub(HttpResponse::class);
        $response->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addSuccessMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->successMessages[] = (string) $message;
            return $messages;
        });

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn (string $path, ?array $params = null) => 'https://shop.test/' . $path
                . ($params ? '?' . http_build_query($params) : '')
        );

        return new CreateAccount(
            $request,
            $checkoutSession,
            $this->customerSession,
            $registration,
            $accounts,
            $orderRepository,
            $extractor,
            $this->customerAssignment,
            $this->createStub(LayoutFactory::class),
            $redirectFactory,
            $response,
            $messages,
            $url,
            $this->createStub(LoggerInterface::class)
        );
    }
}
