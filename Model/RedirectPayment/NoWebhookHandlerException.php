<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

/**
 * No webhook handler is registered for the order's payment method: retrying
 * the call can't help (Webhooks::handler, WebhookConsumer).
 */
class NoWebhookHandlerException extends \InvalidArgumentException
{
}
