<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Another process (the provider's webhook, the customer's return, the cron)
 * is updating the order. A LocalizedException on purpose: the webhook
 * controllers already answer 503 for one, so the provider calls again.
 */
class OrderLockedException extends LocalizedException
{
    public function __construct(string $incrementId)
    {
        parent::__construct(new Phrase('Order %1 is being updated by another process.', [$incrementId]));
    }
}
