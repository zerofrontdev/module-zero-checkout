<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Zero\Core\Controller\PrivateFragmentActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;

/**
 * HTMX shipping-rate refresh: address fields hx-get this with the whole form;
 * the address is applied as Placeorder does and #shipping-methods plus the
 * totals (hx-swap-oob) come back. In memory only:
 * - the posted shipping_method is kept when still offered, or each edit would
 *   reset the radio to the first rate;
 * - setTotalsCollectedFlag(false) before collectTotals(): apply() collected
 *   once already, and the flag would leave shipping out of the total.
 */
class Shippingmethods implements PrivateFragmentActionInterface
{
    private const BLOCK_NAME = 'zero.checkout.shippingmethods';
    private const TOTALS_BLOCK_NAME = 'zero.checkout.ordersummarytotals';
    private const ITEMS_BLOCK_NAME = 'zero.checkout.ordersummaryitems';

    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly ShippingAddressUpdater $addressUpdater,
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @return Layout|Raw
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        // The cart was emptied elsewhere on the page (the mini-cart): nothing left to check out.
        if (!$quote->getItemsCount()) {
            /** @var Raw $empty */
            $empty = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $empty->setHeader('HX-Redirect', $this->url->getUrl('checkout/cart'));

            return $empty->setContents('');
        }
        $this->addressUpdater->apply($quote, $this->request->getParams());

        // The method asked for, else the one already chosen, else the first one offered: a method
        // that is no longer offered (free shipping once the cart is under its minimum) isn't kept.
        $address = $quote->getShippingAddress();
        $rate = null;
        $shippingMethodParam = $this->request->getParam('shipping_method', '');
        foreach ([is_scalar($shippingMethodParam) ? (string) $shippingMethodParam : '', (string) $address->getShippingMethod()] as $code) {
            $rate = $code !== '' ? $address->getShippingRateByCode($code) : false;
            if ($rate) {
                break;
            }
        }
        if (!$rate && !$quote->isVirtual()) {
            $rates = $address->getAllShippingRates();
            $rate = $rates ? reset($rates) : null;
        }
        if ($rate && $rate->getCode() !== $address->getShippingMethod()
            || $rate && $this->request->getParam('shipping_method')
        ) {
            $address->setShippingDescription(trim($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle(), ' -'));
            $address->setShippingMethod($rate->getCode());
            $address->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
        }

        $resultLayout = $this->resultLayoutFactory->create();
        $resultLayout->addDefaultHandle();
        $resultLayout->getLayout()->addOutputElement(self::BLOCK_NAME);
        $resultLayout->getLayout()->addOutputElement(self::TOTALS_BLOCK_NAME);
        $resultLayout->getLayout()->addOutputElement(self::ITEMS_BLOCK_NAME);

        return $resultLayout;
    }
}
