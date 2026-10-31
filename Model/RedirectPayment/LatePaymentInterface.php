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
 * A webhook handler that can tell whether a CANCELLED order was paid anyway
 * (the customer paid on the provider's page after the shop gave up on it).
 * Webhooks::settle then flags the order (OrderPayment::paidAfterCancel)
 * instead of running the handler, which would act on a cancelled order.
 */
interface LatePaymentInterface
{
    /**
     * Called only for a cancelled order, under its lock. Read-only: it may ask
     * the provider's status endpoint and nothing else; it must not capture,
     * authorize, cancel, flag or save anything.
     *
     * @return array{id: string, amount: string, currency: string}|null what the provider holds for the order,
     *     null when it reports nothing paid or held
     * @throws LocalizedException when the provider can't be asked
     */
    public function latePayment(Order $order): ?array;
}
