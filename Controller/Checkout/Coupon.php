<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Quote\Api\CouponManagementInterface;
use Zero\Checkout\Model\Config;

/**
 * Discount code in the order summary (HTMX POST from checkout/coupon.phtml;
 * form key checked by core's CSRF validation). Applies `coupon_code`, or
 * removes the current code when `remove` is sent, through core's
 * CouponManagement (same rules and messages as the cart page), then answers
 * with the coupon block and the totals (hx-swap-oob) re-rendered.
 */
class Coupon implements HttpPostActionInterface
{
    private const COUPON_BLOCK_NAME = 'zero.checkout.coupon';
    private const TOTALS_BLOCK_NAME = 'zero.checkout.ordersummarytotals';

    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CouponManagementInterface $couponManagement,
        private readonly RequestInterface $request,
        private readonly Config $config
    ) {
    }

    /**
     * @return Layout
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $error = null;
        $code = $this->request->getParam('coupon_code', '');
        $code = trim(is_scalar($code) ? (string) $code : '');

        if ($this->config->isCouponEnabled() && $quote->getId()) {
            try {
                if ($this->request->getParam('remove')) {
                    $this->couponManagement->remove((int) $quote->getId());
                    $code = '';
                } elseif ($code !== '') {
                    $this->couponManagement->set((int) $quote->getId(), $code);
                } else {
                    $error = (string) __('Enter a discount code.');
                }
            } catch (LocalizedException $e) {
                $error = $e->getMessage();
            }
        }

        $resultLayout = $this->resultLayoutFactory->create();
        $resultLayout->addDefaultHandle();
        $layout = $resultLayout->getLayout();
        $couponBlock = $layout->getBlock(self::COUPON_BLOCK_NAME);
        if ($couponBlock) {
            $couponBlock->setData('couponError', $error);
            $couponBlock->setData('submittedCode', $error !== null ? $code : '');
        }
        $layout->addOutputElement(self::COUPON_BLOCK_NAME);
        $layout->addOutputElement(self::TOTALS_BLOCK_NAME);
        // The cart's totals changed: the mini-cart and other fragments of the cart refetch.
        $resultLayout->setHeader('HX-Trigger', 'cart:updated');

        return $resultLayout;
    }
}
