<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * A redirect payment's "the customer is back, what happened?": asks the
 * provider about the order's payment, captures, cancels or flags the order
 * accordingly and says which (a ReturnResult state). Registered per payment
 * method code in Resolver\PaymentReturn's `updaters`, so a headless
 * storefront finishes any of them through one mutation.
 */
interface OrderUpdaterInterface
{
    /**
     * @param array<string, string> $params what the provider appended to the return URL (an outcome, a token)
     * @return string a ReturnResult state
     * @throws LocalizedException when the provider doesn't answer
     */
    public function update(Order $order, array $params = []): string;
}
