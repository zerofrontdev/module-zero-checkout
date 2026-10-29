<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Api\AfterPlaceOrderInterface;
use Zero\Checkout\Api\PlaceOrderValidatorInterface;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;
use Zero\Checkout\Model\SupportedPaymentMethods;

/**
 * Final submit of Zero Checkout (POST): applies the addresses
 * (ShippingAddressUpdater, as the rate refresh does), shipping and payment,
 * places the order with CartManagementInterface::placeOrder() and redirects to
 * checkout/onepage/success (the last_* session ids are set as Onepage does).
 * The form key is checked in execute(). A method with an off-site step leaves
 * a REDIRECT_URL on the payment.
 *
 * Extension points (di.xml, see Api/ and README.md): `validators` can stop the
 * order with a message; `afterPlaceOrder` runs after it, failures only logged.
 */
class Placeorder implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Payment additional information: where to send the customer once the order
     * exists (a payment page off the site).
     */
    public const REDIRECT_URL = 'zero_redirect_url';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly ShippingAddressUpdater $addressUpdater,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MessageManager $messageManager,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly LoggerInterface $logger,
        private readonly SupportedPaymentMethods $supportedPaymentMethods,
        private readonly array $validators = [],
        private readonly array $afterPlaceOrder = []
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Your session has expired. Please try again.'));
            $resultRedirect->setPath('zero/checkout/index');

            return $resultRedirect;
        }

        $quote = $this->checkoutSession->getQuote();
        $params = $this->request->getParams();

        $shippingMethod = (string) ($params['shipping_method'] ?? '');
        // Only virtual items (licenses, gift cards): nothing to ship.
        if ($shippingMethod === '' && !$quote->isVirtual()) {
            $this->messageManager->addErrorMessage(__('Please select a shipping method.'));
            $resultRedirect->setPath('zero/checkout/index');

            return $resultRedirect;
        }

        $paymentMethod = (string) ($params['payment_method'] ?? '');
        if (!$this->supportedPaymentMethods->isSupported($paymentMethod)) {
            $this->messageManager->addErrorMessage(__('Please select a payment method.'));
            $resultRedirect->setPath('zero/checkout/index');

            return $resultRedirect;
        }

        try {
            $this->addressUpdater->apply($quote, $params);
            $this->addressUpdater->applyBilling($quote, $params);

            if (!$quote->isVirtual()) {
                $shippingAddress = $quote->getShippingAddress();
                $rate = $shippingAddress->getShippingRateByCode($shippingMethod);
                if (!$rate) {
                    throw new LocalizedException(
                        __('The selected shipping method is no longer available for this address.')
                    );
                }
                $shippingDescription = trim($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle(), ' -');
                $shippingAddress->setShippingDescription($shippingDescription);
                $shippingAddress->setShippingMethod($shippingMethod);
                $shippingAddress->setCollectShippingRates(true);
            }

            // Card tokens/ids filled in by each payment form's own JS, passed through
            // generically in additional_data (keys a method doesn't send are just absent).
            // Each method re-verifies its own server-side: Braintree's DataAssignObserver
            // reads the nonce; Stripe/Authorize.Net capture() verify the intent / charge
            // with the opaque token.
            $additionalData = [];
            if (isset($params['payment_method_nonce']) && $params['payment_method_nonce'] !== '') {
                $additionalData['payment_method_nonce'] = (string) $params['payment_method_nonce'];
            }
            if (isset($params['stripe_payment_intent_id']) && $params['stripe_payment_intent_id'] !== '') {
                $additionalData['stripe_payment_intent_id'] = (string) $params['stripe_payment_intent_id'];
            }
            if (isset($params['authnet_data_descriptor']) && $params['authnet_data_descriptor'] !== '') {
                $additionalData['authnet_data_descriptor'] = (string) $params['authnet_data_descriptor'];
            }
            if (isset($params['authnet_data_value']) && $params['authnet_data_value'] !== '') {
                $additionalData['authnet_data_value'] = (string) $params['authnet_data_value'];
            }
            // Any other method: fields named payment_additional_data[<key>] (scalars only)
            // reach its assignData() / payment additional_data unchanged.
            foreach ((array) ($params['payment_additional_data'] ?? []) as $key => $value) {
                if (is_string($key) && is_scalar($value) && !isset($additionalData[$key])) {
                    $additionalData[$key] = (string) $value;
                }
            }
            // Purchase Order: stock Purchaseorder::assignData() reads po_number from the
            // top level (not additional_data); its validate() rejects an empty one.
            $paymentData = ['method' => $paymentMethod, 'additional_data' => $additionalData];
            if (isset($params['po_number']) && $params['po_number'] !== '') {
                $paymentData['po_number'] = (string) $params['po_number'];
            }
            $quote->getPayment()->importData($paymentData);

            foreach ($this->validators as $validator) {
                if ($validator instanceof PlaceOrderValidatorInterface) {
                    $validator->validate($quote, $params);
                }
            }

            $this->addressUpdater->markAddressBookEntries(
                $quote,
                ($params['billing_same_as_shipping'] ?? '1') !== '0'
            );

            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            // Nothing left to pay (a gift card, a 100% discount): no payment to take,
            // whichever method the page still had chosen.
            if ((float) $quote->getBaseGrandTotal() <= 0.0001 && $paymentMethod !== 'free'
                && $this->supportedPaymentMethods->isSupported('free')
            ) {
                $quote->getPayment()->importData(['method' => 'free']);
            }
            // Persist the shipping method first: placeOrder() re-reads the quote from the
            // repository and would otherwise reject it with "shipping method is missing".
            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder($quote->getId());
            $order = $this->orderRepository->get($orderId);

            $this->checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getEntityId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            $this->runAfterPlaceOrder($order, $params);

            // Methods that need an off-site step leave a redirect URL on the payment during
            // placeOrder(): `zero_redirect_url` (Zero_Monobank, Zero_LiqPay) is followed as
            // it is, Zero_Paypal's goes through its own controller; everything else goes
            // straight to success.
            $redirectUrl = (string) $order->getPayment()?->getAdditionalInformation(self::REDIRECT_URL);
            $paypalRedirectUrl = $order->getPayment()?->getAdditionalInformation('paypal_redirect_url');
            if ($redirectUrl !== '') {
                $resultRedirect->setUrl($redirectUrl);
            } elseif ($paypalRedirectUrl) {
                $resultRedirect->setPath('zero_paypal/standard/redirect');
            } else {
                $resultRedirect->setPath('checkout/onepage/success');
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $resultRedirect->setPath('zero/checkout/index');
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('We can\'t place the order right now. Please try again.')
            );
            $resultRedirect->setPath('zero/checkout/index');
        }

        return $resultRedirect;
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array<string, mixed> $params
     */
    private function runAfterPlaceOrder($order, array $params): void
    {
        foreach ($this->afterPlaceOrder as $name => $handler) {
            if (!$handler instanceof AfterPlaceOrderInterface) {
                continue;
            }
            try {
                $handler->execute($order, $params);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Zero Checkout after-place-order handler "%s" failed for order %s: %s',
                        $name, $order->getIncrementId(), $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }
}
