<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Zero Checkout settings (Stores > Configuration > Zero > Checkout; defaults in
 * etc/config.xml). One place for the paths, read by every class that branches
 * on them.
 */
class Config
{
    public const XML_PATH_ENABLED = 'checkout/zero_checkout/enabled';
    public const XML_PATH_COUPON = 'checkout/zero_checkout/coupon';
    public const XML_PATH_ORDER_COMMENT = 'checkout/zero_checkout/order_comment';
    public const XML_PATH_LOGIN = 'checkout/zero_checkout/login';
    public const XML_PATH_LAYOUT = 'checkout/zero_checkout/layout';
    public const LAYOUT_ONE_PAGE = 'one_page';
    public const LAYOUT_STEPS = 'steps';
    public const XML_PATH_ADDRESS_AUTOCOMPLETE = 'checkout/zero_checkout/address_autocomplete';
    public const XML_PATH_WEBHOOK_QUEUE = 'checkout/zero_checkout/webhook_queue';
    public const XML_PATH_RECHECK_PENDING = 'checkout/zero_checkout/recheck_pending';
    public const XML_PATH_HEADLESS_URL = 'checkout/zero_checkout/headless_url';
    /** Magento's own: Customers > Customer Configuration > Name and Address Options > Show Company. */
    public const XML_PATH_COMPANY_SHOW = 'customer/address/company_show';
    /** Magento's own: Customers > Customer Configuration > Create New Account Options > Show VAT Number on Storefront. */
    public const XML_PATH_VAT_SHOW = 'customer/create_account/vat_frontend_visibility';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param int|string|null $storeId null = current store
     * @return bool
     */
    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** Discount code field in the order summary. */
    /**
     * Payment Webhooks > Process Through the Queue (RedirectPayment\Webhooks).
     */
    public function isWebhookQueued(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_WEBHOOK_QUEUE);
    }

    /**
     * Payment Webhooks > Recheck Pending Payments (Cron\RecheckPending). Off by default.
     */
    public function isRecheckPending(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RECHECK_PENDING, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isCouponEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_COUPON, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** Optional "note to the store" field, saved on the order. */
    public function isOrderCommentEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ORDER_COMMENT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** Checkout in three steps (address, shipping, payment) instead of one page. */
    public function isStepLayout(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->getValue(self::XML_PATH_LAYOUT, ScopeInterface::SCOPE_STORE, $storeId)
            === self::LAYOUT_STEPS;
    }

    /** Address autocomplete provider code ('' = off), see Model\AddressLookup\Pool. */
    public function getAddressAutocomplete(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ADDRESS_AUTOCOMPLETE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** "Already have an account? Sign in" for guests, on the checkout page. */
    /**
     * The Company field: '' (hidden), 'opt' or 'req'.
     */
    public function getCompanyField(int|string|null $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_COMPANY_SHOW, ScopeInterface::SCOPE_STORE, $storeId);

        return in_array($value, ['opt', 'req'], true) ? $value : '';
    }

    public function isVatFieldShown(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VAT_SHOW, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isLoginEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOGIN, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The headless storefront's origin for this store view ('' when the Zero
     * theme is the storefront): redirect payments send the customer back there.
     */
    public function headlessUrl(?int $storeId = null): string
    {
        $url = trim((string) $this->scopeConfig->getValue(self::XML_PATH_HEADLESS_URL, ScopeInterface::SCOPE_STORE, $storeId));

        return preg_match('#^https?://#i', $url) ? rtrim($url, '/') : '';
    }
}
