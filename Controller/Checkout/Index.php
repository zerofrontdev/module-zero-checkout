<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Url\EncoderInterface as UrlEncoder;
use Magento\Framework\UrlInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;
use Zero\Core\Controller\PrivateFragmentActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Zero Checkout entry point (zero/checkout/index): the one-page, JS-free
 * checkout that replaces stock's Knockout checkout. Page content comes from
 * layout zero_checkout_index.xml; data from ViewModel\Checkout\CheckoutPage.
 *
 * A logged-in customer whose quote has no shipping address yet starts from
 * their default shipping address (rates and totals included), as in stock
 * checkout; the saved-address picker switches to another one.
 *
 * A guest whose cart can't be checked out as a guest (Allow Guest Checkout
 * is off, or the cart has downloadable items and the store disables guest
 * checkout for them) goes to the sign-in page and comes back here after it.
 */
class Index implements PrivateFragmentActionInterface
{
    public function __construct(
        private readonly PageFactory $resultPageFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly ResultFactory $resultFactory,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly ShippingAddressUpdater $addressUpdater,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutHelper $checkoutHelper,
        private readonly MessageManager $messageManager,
        private readonly UrlInterface $url,
        private readonly UrlEncoder $urlEncoder
    ) {
    }

    /**
     * @return Page|Redirect
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getItemsCount()) {
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('checkout/cart');

            return $resultRedirect;
        }

        if (!$this->customerSession->isLoggedIn() && !$this->checkoutHelper->isAllowedGuestCheckout($quote)) {
            // Back to checkout after signing in: the session's after-auth URL when the store
            // redirects to the dashboard after login, the referer parameter when it doesn't.
            $checkoutUrl = $this->url->getUrl('zero/checkout/index');
            $this->customerSession->setAfterAuthUrl($checkoutUrl);
            $this->messageManager->addNoticeMessage(__('Sign in or create an account to check out.'));
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('customer/account/login', ['referer' => $this->urlEncoder->encode($checkoutUrl)]);

            return $resultRedirect;
        }

        $this->prefillDefaultShippingAddress($quote);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('Checkout'));

        return $resultPage;
    }

    private function prefillDefaultShippingAddress(Quote $quote): void
    {
        $customer = $quote->getCustomer();
        if ($quote->isVirtual()
            || !$customer || !$customer->getId()
            || !$customer->getDefaultShipping()
            || $quote->getShippingAddress()->getPostcode()
        ) {
            return;
        }

        try {
            $address = $this->addressRepository->getById((int) $customer->getDefaultShipping());
        } catch (LocalizedException $e) {
            return;
        }
        if ((int) $address->getCustomerId() === (int) $customer->getId()) {
            $this->addressUpdater->applyCustomerAddress($quote, $address);
        }
    }
}
