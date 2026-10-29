<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\ViewModel\Checkout;

use Magento\Bundle\Helper\Catalog\Product\Configuration as BundleConfiguration;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Catalog\Helper\Product\ConfigurationPool;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Downloadable\Helper\Catalog\Product\Configuration as DownloadableConfiguration;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Item;
use Magento\Tax\Model\Config as TaxConfig;
use Zero\Checkout\Model\Config;
use Zero\Checkout\Model\SupportedPaymentMethods;

/**
 * Zero Checkout's page data: quote, addresses, address book, countries and
 * regions, shipping rates, totals and payment methods. Modules with their own
 * payment forms (Stripe, Authorize.Net) only ask isPaymentMethodAvailable().
 */
class CheckoutPage implements ArgumentInterface
{
    /**
     * Per-type helpers that list a cart item's chosen options, as the cart page's
     * item renderers use. Configurable needs none: its attributes come from a
     * plugin on the default (catalog) helper.
     */
    private const OPTION_HELPERS = [
        'bundle' => BundleConfiguration::class,
        'downloadable' => DownloadableConfiguration::class,
    ];

    /** @var MethodInterface[]|null */
    private ?array $availablePaymentMethods = null;

    /** @var array<string, \Magento\Quote\Model\Quote\Address\Total>|null */
    private ?array $totals = null;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly PricingHelper $pricingHelper,
        private readonly FormKey $formKey,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly MethodList $methodList,
        private readonly ConfigurationPool $configurationPool,
        private readonly Config $config,
        private readonly SupportedPaymentMethods $supportedPaymentMethods,
        private readonly DirectoryHelper $directoryHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TaxConfig $taxConfig
    ) {
    }

    /** Stores > Configuration > Zero > Checkout > Discount Code Field. */
    public function isCouponEnabled(): bool
    {
        return $this->config->isCouponEnabled();
    }

    /** Stores > Configuration > Zero > Checkout > Order Comment. */
    public function isOrderCommentEnabled(): bool
    {
        return $this->config->isOrderCommentEnabled();
    }

    /** Stores > Configuration > Zero > Checkout > Sign In at Checkout. */
    public function isLoginEnabled(): bool
    {
        return $this->config->isLoginEnabled();
    }

    public function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

    public function getShippingAddress(): Address
    {
        return $this->getQuote()->getShippingAddress();
    }

    /**
     * Billing address of the current quote, to pre-fill the billing fieldset.
     */
    public function getBillingAddress(): Address
    {
        return $this->getQuote()->getBillingAddress();
    }

    /**
     * @return Item[]
     */
    public function getItems(): array
    {
        return array_values($this->getQuote()->getAllVisibleItems());
    }

    /** Street suggestions as the shopper types (Zero > Checkout > Address Autocomplete). */
    public function isAddressAutocompleteEnabled(): bool
    {
        return $this->config->getAddressAutocomplete() !== '';
    }

    /** Company in the address: '' (hidden), 'opt' or 'req' (Magento's Show Company). */
    public function getCompanyField(): string
    {
        return $this->config->getCompanyField();
    }

    /** VAT number in the address, checked in VIES (Magento's Show VAT Number on Storefront). */
    public function isVatFieldShown(): bool
    {
        return $this->config->isVatFieldShown();
    }

    /** Three steps instead of one page (Stores > Configuration > Zero > Checkout). */
    public function isStepLayout(): bool
    {
        return $this->config->isStepLayout();
    }

    /**
     * Only virtual items (licenses, gift cards, downloads): nothing to ship,
     * so no shipping method and one address, the billing one.
     */
    public function isVirtual(): bool
    {
        return (bool) $this->getQuote()->isVirtual();
    }

    public function isGuest(): bool
    {
        return !$this->customerSession->isLoggedIn();
    }

    public function getCustomerEmail(): string
    {
        if (!$this->isGuest()) {
            return (string) $this->customerSession->getCustomer()->getEmail();
        }

        return (string) $this->getQuote()->getCustomerEmail();
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * The subtotal with or without tax, as Sales > Tax > Shopping Cart Display
     * Settings says (prices with GST inside for an Australian store).
     */
    public function getFormattedSubtotal(): string
    {
        $quote = $this->getQuote();
        $subtotal = (float) $quote->getSubtotal();
        if ($this->taxConfig->displayCartSubtotalInclTax($quote->getStoreId())
            || $this->taxConfig->displayCartSubtotalBoth($quote->getStoreId())) {
            $total = $this->totals()['subtotal'] ?? null;
            $subtotal = (float) ($total?->getValueInclTax() ?: $subtotal);
        }

        return $this->formatQuoteAmount($subtotal);
    }

    /**
     * The order's tax for the summary, or null when there is none: `included`
     * when the prices shown already hold it (then it reads "Includes GST $38.63"
     * under the total), otherwise a line of its own before the total.
     *
     * @return array{title: string, value: string, included: bool}|null
     */
    public function getTaxLine(): ?array
    {
        $quote = $this->getQuote();
        $total = $this->totals()['tax'] ?? null;
        $amount = (float) $total?->getValue();
        if ($amount <= 0.0) {
            return null;
        }
        $storeId = $quote->getStoreId();

        return [
            'title' => (string) ($total->getTitle() ?: __('Tax')),
            'value' => $this->formatQuoteAmount($amount),
            'included' => $this->taxConfig->displayCartSubtotalInclTax($storeId)
                || $this->taxConfig->displayCartSubtotalBoth($storeId),
        ];
    }

    public function getFormattedShippingAmount(): ?string
    {
        $address = $this->getShippingAddress();
        if (!$address->getShippingMethod()) {
            return null;
        }

        $storeId = $this->getQuote()->getStoreId();
        $inclTax = $this->taxConfig->displayCartShippingInclTax($storeId) || $this->taxConfig->displayCartShippingBoth($storeId);

        return $this->formatQuoteAmount((float) ($inclTax ? $address->getShippingInclTax() : $address->getShippingAmount()));
    }

    /**
     * Cart price rule discount as a formatted amount, or null when there's none.
     * Without this row the summary showed Subtotal → Total with an unexplained gap.
     */
    public function getFormattedDiscountAmount(): ?string
    {
        $address = $this->getShippingAddress();
        $discount = (float) $address->getDiscountAmount();
        if ($discount >= 0.0) {
            return null;
        }

        return $this->formatQuoteAmount($discount);
    }

    /**
     * Totals other modules add (a gift card, say), between Discount and
     * Total: each total the quote shows that this summary doesn't already.
     *
     * @return array<int, array{code: string, title: string, value: string}>
     */
    public function getExtraTotals(): array
    {
        $extra = [];
        foreach ($this->totals() as $code => $total) {
            if (in_array($code, self::SUMMARY_TOTALS, true) || !(float) $total->getValue()) {
                continue;
            }
            $extra[] = [
                'code' => (string) $code,
                'title' => (string) $total->getTitle(),
                'value' => $this->formatQuoteAmount((float) $total->getValue()),
            ];
        }

        return $extra;
    }

    /**
     * The quote's totals, collected once per render: Quote::getTotals() runs every
     * collector's fetch() on each call, and the summary asks three times.
     *
     * @return array<string, \Magento\Quote\Model\Quote\Address\Total>
     */
    private function totals(): array
    {
        return $this->totals ??= $this->getQuote()->getTotals();
    }

    public function getFormattedGrandTotal(): string
    {
        return $this->formatQuoteAmount((float) $this->getQuote()->getGrandTotal());
    }

    /**
     * The item's chosen options (size, color, bundle selections, custom options,
     * links) for the order summary: two hoodies in different sizes otherwise
     * read as the same line twice.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getItemOptions(Item $item): array
    {
        $helper = $this->configurationPool->get(
            self::OPTION_HELPERS[$item->getProductType()] ?? ProductConfiguration::class
        );
        $options = [];
        foreach ($helper->getOptions($item) as $option) {
            $value = $option['print_value'] ?? $option['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $options[] = [
                'label' => (string) ($option['label'] ?? ''),
                'value' => trim(preg_replace('/\s+/', ' ', strip_tags((string) $value))),
            ];
        }

        return $options;
    }

    public function getFormattedItemPrice(Item $item): string
    {
        $storeId = $this->getQuote()->getStoreId();
        $inclTax = $this->taxConfig->displayCartPricesInclTax($storeId) || $this->taxConfig->displayCartPricesBoth($storeId);

        return $this->formatQuoteAmount((float) ($inclTax ? ($item->getRowTotalInclTax() ?: $item->getRowTotal()) : $item->getRowTotal()));
    }

    /**
     * An amount of the quote (totals, row totals, shipping amount): already in
     * the quote's currency, so it is formatted, not converted again. (Converting
     * it, as for a base-currency price, multiplied it by the rate a second time
     * in store views that show another currency.)
     */
    private function formatQuoteAmount(float $amount): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $this->getQuote()->getQuoteCurrencyCode() ?: null
        );
    }

    /**
     * A base-currency amount (a shipping rate), converted to the store view's currency.
     */
    public function getFormattedPrice(float $value): string
    {
        return $this->pricingHelper->currency($value, true, false);
    }

    /**
     * The logged-in customer's address-book entries for the saved-address picker;
     * empty for guests.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function getSavedAddresses(): array
    {
        if ($this->isGuest()) {
            return [];
        }

        $result = [];
        foreach ($this->customerSession->getCustomer()->getAddresses() as $address) {
            $line = trim(implode(' ', [
                $address->getFirstname(),
                $address->getLastname(),
            ]));
            $streetLine = trim((string) $address->getStreetLine(1));
            $cityLine = trim(implode(', ', array_filter([
                $address->getCity(),
                $address->getRegionCode() ?: $address->getRegion(),
                $address->getPostcode(),
            ])));
            $label = trim(implode(' — ', array_filter([$line, $streetLine, $cityLine])));

            $result[] = [
                'id' => (int) $address->getId(),
                'label' => $label !== '' ? $label : (string) __('Address #%1', $address->getId()),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string> country_id => name
     */
    public function getCountries(): array
    {
        $collection = $this->countryCollectionFactory->create()->loadByStore();
        $options = [];
        foreach ($collection as $country) {
            $options[$country->getCountryId()] = $country->getName();
        }

        return $options;
    }

    /**
     * The country preselected while the address has none: the store view's
     * Default Country (General > Country Options).
     */
    public function getDefaultCountryId(): string
    {
        return (string) $this->directoryHelper->getDefaultCountry() ?: 'US';
    }

    /**
     * @return array<int, string> region_id => name, for the CURRENT shipping address's own country
     */
    public function getRegionsForCurrentCountry(): array
    {
        $countryId = $this->getShippingAddress()->getCountryId() ?: $this->getDefaultCountryId();

        return $this->getRegionsForCountry($countryId);
    }

    /**
     * @return array<int, string> region_id => name
     */
    public function getRegionsForCountry(string $countryId): array
    {
        $collection = $this->regionCollectionFactory->create()->addCountryFilter($countryId);
        $options = [];
        foreach ($collection as $region) {
            $options[$region->getRegionId()] = $region->getName();
        }

        return $options;
    }

    /**
     * Rates for the current shipping address; empty until an address has been
     * applied (Controller\Checkout\Shippingmethods).
     *
     * @return Rate[]
     */
    public function getAvailableShippingRates(): array
    {
        return array_values($this->getShippingAddress()->getAllShippingRates() ?: []);
    }

    public function getSelectedShippingMethod(): ?string
    {
        return $this->getShippingAddress()->getShippingMethod() ?: null;
    }

    public function hasShippingAddress(): bool
    {
        return (bool) $this->getShippingAddress()->getPostcode();
    }

    /**
     * Built-in payment method codes Zero Checkout can complete; modules add theirs
     * through Model\SupportedPaymentMethods (di.xml), which both the list
     * (getAvailablePaymentMethods) and the submit check (Placeorder) read, so the
     * two can't disagree. Offline methods (checkmo, cashondelivery, banktransfer,
     * purchaseorder, free) need no extra handling beyond the PO number field;
     * `free` is what Magento requires for a $0 grand total.
     */
    /** Totals the summary shows itself (or leaves out: tax is in the prices or the total). */
    private const SUMMARY_TOTALS = ['subtotal', 'shipping', 'discount', 'grand_total', 'tax', 'weee', 'weee_tax'];

    public const SUPPORTED_METHODS = [
        'checkmo', 'cashondelivery', 'banktransfer', 'purchaseorder', 'free',
        'zero_paypal_standard', 'braintree', 'zero_stripe', 'zero_authorizenet',
    ];

    /**
     * Methods available for this quote (MethodList: enabled, country/currency/
     * amount rules), narrowed to SUPPORTED_METHODS — MethodList returns every
     * method Magento considers available, and anything not supported here would
     * be rejected on submit.
     *
     * @return MethodInterface[]
     */
    public function getAvailablePaymentMethods(): array
    {
        // Memoized: MethodList runs every method's own isAvailable() checks,
        // and the page asks for this list several times per render (list,
        // each payment module's own form block).
        if ($this->availablePaymentMethods === null) {
            $this->availablePaymentMethods = array_values(array_filter(
                $this->methodList->getAvailableMethods($this->getQuote()),
                fn (MethodInterface $method): bool => $this->supportedPaymentMethods->isSupported($method->getCode())
            ));
        }

        return $this->availablePaymentMethods;
    }

    /**
     * Nothing left to pay (a gift card or a 100% discount covers the order).
     */
    public function isNothingToPay(): bool
    {
        return (float) $this->getQuote()->getBaseGrandTotal() <= 0.0001
            && $this->isPaymentMethodAvailable('free');
    }

    /**
     * The payment choices to list: only "No Payment Information Required" when
     * there is nothing to pay, as core's zero-total check allows; otherwise
     * every available method but that one. (The methods' forms render for
     * every available method, so they are there when the list changes.)
     *
     * @return MethodInterface[]
     */
    public function getPaymentMethodsToList(): array
    {
        $nothingToPay = $this->isNothingToPay();

        return array_values(array_filter(
            $this->getAvailablePaymentMethods(),
            static fn (MethodInterface $method): bool => ($method->getCode() === 'free') === $nothingToPay
        ));
    }

    /**
     * Whether a method is both supported here and currently available for this quote.
     *
     * @param string $code
     * @return bool
     */
    public function isPaymentMethodAvailable(string $code): bool
    {
        foreach ($this->getAvailablePaymentMethods() as $method) {
            if ($method->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * One-line explanation shown under a payment method's title, '' if none.
     *
     * @param string $code
     * @return string
     */
    public function getPaymentDescription(string $code): string
    {
        $descriptions = [
            'checkmo' => __('Mail a check or money order — no online payment needed.'),
            'cashondelivery' => __('Pay in cash when your order is delivered — no online payment needed.'),
            'banktransfer' => __('Transfer the total to our bank account — details follow in your order confirmation.'),
            'purchaseorder' => __('Pay against your company\'s purchase order.'),
            'free' => __('Nothing to pay for this order.'),
            'zero_paypal_standard' => __('You\'ll be redirected to PayPal to complete your payment.'),
            'braintree' => __('Pay by credit or debit card.'),
            'zero_stripe' => __('Pay by credit or debit card.'),
            'zero_authorizenet' => __('Pay by credit or debit card.'),
        ];

        return isset($descriptions[$code])
            ? (string) $descriptions[$code]
            : $this->supportedPaymentMethods->getDescription($code);
    }
}
