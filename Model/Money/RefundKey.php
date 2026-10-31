<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\Money;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * An idempotency key / reference for a refund request, the same when Magento
 * retries the same credit memo and different for another partial refund.
 *
 * Magento calls the method's refund() inside the credit memo's transaction,
 * before the memo is saved, so nothing stored on the memo survives a rollback.
 * The key is built from what is persisted instead: the amount refunded on the
 * payment so far (updated only after the gateway call succeeded) and this
 * refund's amount, both in the order's base currency.
 *
 * A prefix longer than $maxLength - 8 is cut to that length, so the amounts
 * or the hash always fit.
 *
 * Provider caveat: Stripe replays the stored result of the first request with
 * a key for 24 hours, so a deliberate retry of a failed refund with the same
 * amount within that time gets the same error back.
 */
class RefundKey
{
    /**
     * @param int $maxLength the provider's limit for the key
     */
    public static function for(InfoInterface $payment, float $baseAmount, string $prefix = '', int $maxLength = 64): string
    {
        /** @var Payment $payment */
        $prefix = substr($prefix, 0, max(0, $maxLength - 8));
        $order = $payment->getOrder();
        $currency = (string) $order->getBaseCurrencyCode();
        $key = sprintf(
            '%s%s-r%d-%d',
            $prefix,
            $order->getIncrementId(),
            MinorUnits::toMinor((float) $payment->getBaseAmountRefunded(), $currency),
            MinorUnits::toMinor($baseAmount, $currency)
        );
        if (strlen($key) <= $maxLength) {
            return $key;
        }

        return $prefix . substr(sha1($key), 0, max(0, min(40, $maxLength - strlen($prefix))));
    }
}
