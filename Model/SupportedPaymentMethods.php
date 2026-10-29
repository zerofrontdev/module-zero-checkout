<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model;

use Zero\Checkout\ViewModel\Checkout\CheckoutPage;

/**
 * Payment methods Zero Checkout lists and accepts: the built-in ones
 * (CheckoutPage::SUPPORTED_METHODS) plus any registered in di.xml:
 *
 *     <type name="Zero\Checkout\Model\SupportedPaymentMethods">
 *         <arguments>
 *             <argument name="methods" xsi:type="array">
 *                 <item name="acme_pay" xsi:type="string" translate="true">Pay with your Acme wallet.</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * Item name = method code, value = the line under its title ('' for none).
 * The method must still be enabled and available. The line is translated
 * when shown (di.xml values are not): the module's i18n/<locale>.csv has it.
 */
class SupportedPaymentMethods
{
    /**
     * @param array<string, string> $methods method code => description
     */
    public function __construct(
        private readonly array $methods = []
    ) {
    }

    /**
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_values(array_unique(array_merge(CheckoutPage::SUPPORTED_METHODS, array_keys($this->methods))));
    }

    public function isSupported(string $code): bool
    {
        return in_array($code, $this->getCodes(), true);
    }

    /** Description registered with the code, '' if none. */
    public function getDescription(string $code): string
    {
        $description = (string) ($this->methods[$code] ?? '');

        return $description === '' ? '' : (string) __($description);
    }
}
