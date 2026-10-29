<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\PlaceOrder;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Zero\Checkout\Api\PlaceOrderValidatorInterface;

/**
 * A guest may place the order only when Magento allows guest checkout for
 * this cart: Sales > Checkout > Checkout Options > Allow Guest Checkout, and
 * Catalog > Downloadable Product Options > Disable Guest Checkout if Cart
 * Contains Downloadable Items (core's checkout_allow_guest event). The
 * checkout page sends such a guest to sign in first; this is the server-side
 * check behind it.
 */
class GuestCheckoutValidator implements PlaceOrderValidatorInterface
{
    public function __construct(private readonly CheckoutHelper $checkoutHelper)
    {
    }

    /**
     * @inheritDoc
     */
    public function validate(Quote $quote, array $request): void
    {
        if (!$quote->getCustomerId() && !$this->checkoutHelper->isAllowedGuestCheckout($quote)) {
            throw new LocalizedException(__('Sign in or create an account to place this order.'));
        }
    }
}
