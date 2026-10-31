<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\Checkout;

use Magento\Customer\Api\Data\AddressInterface as CustomerAddress;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;

/**
 * Applies checkout form addresses to the quote, for both the rate refresh
 * and Placeorder, so what's quoted is what's charged. Billing is separate
 * (applyBilling(), Placeorder only). applyCustomerAddress() fills from the
 * address book; markAddressBookEntries() saves a newly typed address to it,
 * as stock checkout does.
 */
class ShippingAddressUpdater
{
    private const ADDRESS_FIELDS = [
        'firstname', 'lastname', 'street', 'city', 'region', 'region_id',
        'postcode', 'country_id', 'telephone', 'company', 'vat_id',
    ];
    /** Fields a shopper may empty (the others keep their value when sent empty). */
    private const CLEARABLE_FIELDS = ['company', 'vat_id'];

    public function __construct(
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param array<string, mixed> $data Raw request data — only the known address fields are read.
     */
    public function apply(Quote $quote, array $data): void
    {
        $address = $quote->getShippingAddress();
        $this->applyFields($address, $data);

        if (!$quote->getCustomer() || !$quote->getCustomer()->getId()) {
            $quote->setCustomerEmail(self::email($quote, $data));
        }

        $address->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
    }

    /**
     * Applies an address-book entry to the shipping address (see apply()).
     */
    public function applyCustomerAddress(Quote $quote, CustomerAddress $customerAddress): void
    {
        $this->apply($quote, $this->customerAddressData($customerAddress));
    }

    /**
     * An address-book entry as the plain field array apply()/applyFields() read.
     *
     * @return array<string, string>
     */
    public function customerAddressData(CustomerAddress $customerAddress): array
    {
        $region = $customerAddress->getRegion();
        $street = $customerAddress->getStreet();

        return [
            'firstname' => (string) $customerAddress->getFirstname(),
            'lastname' => (string) $customerAddress->getLastname(),
            'street' => (string) ($street[0] ?? ''),
            'city' => (string) $customerAddress->getCity(),
            'region' => $region ? (string) $region->getRegion() : '',
            'region_id' => $region ? (string) $region->getRegionId() : '',
            'postcode' => (string) $customerAddress->getPostcode(),
            'country_id' => (string) $customerAddress->getCountryId(),
            'telephone' => (string) $customerAddress->getTelephone(),
            'company' => (string) $customerAddress->getCompany(),
            'vat_id' => (string) $customerAddress->getVatId(),
        ];
    }

    /**
     * Logged-in customers: links each quote address to the address-book entry it
     * matches, or flags it `save_in_address_book` so QuoteManagement saves it
     * (and makes it the default when the customer has none). Without the flag
     * core saves nothing for a logged-in quote, so every order meant retyping
     * the address. With billing same as shipping only one entry is saved.
     */
    public function markAddressBookEntries(Quote $quote, bool $billingSameAsShipping): void
    {
        $customer = $quote->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $savedAddresses = $customer->getAddresses() ?: [];
        $billing = $quote->getBillingAddress();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();

        if ($shipping === null) {
            $this->linkOrFlag($billing, $savedAddresses);

            return;
        }

        $this->linkOrFlag($shipping, $savedAddresses);
        if ($billingSameAsShipping) {
            $isNew = (bool) $shipping->getSaveInAddressBook();
            // Core saves the billing copy and skips a shipping address marked same-as-billing.
            $shipping->setSameAsBilling($isNew ? 1 : 0);
            $shipping->setSaveInAddressBook(0);
            $billing->setSaveInAddressBook($isNew ? 1 : 0);
            $billing->setCustomerAddressId($shipping->getCustomerAddressId());

            return;
        }

        $shipping->setSameAsBilling(0);
        $this->linkOrFlag($billing, $savedAddresses);
    }

    /**
     * @param CustomerAddress[] $savedAddresses
     */
    private function linkOrFlag(Address $address, array $savedAddresses): void
    {
        foreach ($savedAddresses as $savedAddress) {
            if ($this->sameAddress($this->customerAddressData($savedAddress), $address)) {
                $address->setCustomerAddressId((int) $savedAddress->getId());
                $address->setSaveInAddressBook(0);

                return;
            }
        }

        $address->setCustomerAddressId(null);
        $address->setSaveInAddressBook(1);
    }

    /**
     * @param array<string, string> $saved
     */
    private function sameAddress(array $saved, Address $address): bool
    {
        $street = $address->getStreet();
        $current = [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'street' => is_array($street) ? ($street[0] ?? '') : $street,
            'city' => $address->getCity(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
        foreach ($current as $field => $value) {
            if (mb_strtolower(trim((string) $value)) !== mb_strtolower(trim($saved[$field] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Billing saved-address picker: applies a picked address-book entry (plain,
     * unprefixed keys) to the billing address. Ignores billing_same_as_shipping —
     * the picker only renders while that box is unchecked.
     *
     * @param array<string, mixed> $data Raw request data — plain (unprefixed) address field keys.
     */
    public function applyBillingFromAddress(Quote $quote, array $data): void
    {
        $billingAddress = $quote->getBillingAddress();
        $this->applyFields($billingAddress, $data);

        if (!$quote->getCustomer() || !$quote->getCustomer()->getId()) {
            $billingAddress->setEmail(self::email($quote, $data));
        }

        $this->cartRepository->save($quote);
    }

    /**
     * Copies the known ADDRESS_FIELDS from $data onto the given address.
     *
     * @param array<string, mixed> $data
     */
    private function applyFields(Address $address, array $data): void
    {
        $addressData = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            // An array (firstname[]=x) counts as missing: the address keeps its value.
            if (isset($data[$field]) && !is_scalar($data[$field])) {
                continue;
            }
            if (isset($data[$field]) && $data[$field] !== '') {
                $addressData[$field] = $field === 'street' ? [$data[$field]] : $data[$field];
            } elseif (isset($data[$field]) && in_array($field, self::CLEARABLE_FIELDS, true)) {
                $addressData[$field] = null;
            }
        }

        $address->addData($addressData);
    }

    /**
     * The guest's email from the form; an array (email[]=x) counts as missing.
     *
     * @param array<string, mixed> $data
     */
    private static function email(Quote $quote, array $data): string
    {
        $email = $data['email'] ?? null;

        return is_scalar($email) ? (string) $email : (string) $quote->getCustomerEmail();
    }

    /**
     * Applies the billing address. billing_same_as_shipping (checkbox, default
     * checked) picks the source: checked reuses the raw shipping values from $data
     * (not the address getters, which can reformat street); unchecked reads the
     * billing_*-prefixed fields. Saves the quote.
     *
     * @param array<string, mixed> $data Raw request data.
     */
    public function applyBilling(Quote $quote, array $data): void
    {
        $billingAddress = $quote->getBillingAddress();
        $sameAsShipping = ($data['billing_same_as_shipping'] ?? '1') !== '0';

        // Remap to the plain (unprefixed) keys applyFields() reads — checked
        // sources shipping's own raw values, unchecked sources the real
        // billing_*-prefixed ones, either way applyFields() itself never
        // needs to know which.
        $remapped = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $key = $sameAsShipping ? $field : 'billing_' . $field;
            if (isset($data[$key])) {
                $remapped[$field] = $data[$key];
            }
        }
        $this->applyFields($billingAddress, $remapped);

        if (!$quote->getCustomer() || !$quote->getCustomer()->getId()) {
            $billingAddress->setEmail(self::email($quote, $data));
        }

        $this->cartRepository->save($quote);
    }
}
