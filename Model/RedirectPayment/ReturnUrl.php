<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Sales\Model\Order;
use Zero\Checkout\Model\Config;

/**
 * Where a payment provider sends the customer back. With a headless
 * storefront configured for the store view (Zero Checkout > Headless
 * Storefront URL), that is the storefront's /checkout/return page with the
 * order number and the method; otherwise null and the module uses its own
 * Magento return controller as before.
 */
class ReturnUrl
{
    public const PATH = 'checkout/return';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, string> $query more for the storefront to pass back to the provider's updater
     */
    public function headless(Order $order, string $methodCode, array $query = []): ?string
    {
        $base = $this->config->headlessUrl((int) $order->getStoreId());

        return $base === '' ? null : self::build($base, (string) $order->getIncrementId(), $methodCode, $query);
    }

    /**
     * @param array<string, string> $query
     */
    public static function build(string $base, string $orderNumber, string $methodCode, array $query = []): string
    {
        return rtrim($base, '/') . '/' . self::PATH . '?' . http_build_query(['order' => $orderNumber, 'method' => $methodCode] + $query);
    }
}
