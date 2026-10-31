<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\Checkout;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;

#[AllowMockObjectsWithoutExpectations]
class ShippingAddressUpdaterTest extends TestCase
{
    use MockCreationTrait;

    private Address $shipping;
    private Address $billing;
    private Quote&MockObject $quote;
    private CartRepositoryInterface&MockObject $repository;
    private ?CustomerInterface $customer = null;

    protected function setUp(): void
    {
        $this->shipping = $this->address();
        $this->billing = $this->address();
        $this->repository = $this->createMock(CartRepositoryInterface::class);
        $this->quote = $this->createPartialMockWithReflection(Quote::class, [
            'getShippingAddress', 'getBillingAddress', 'getCustomer', 'collectTotals',
            'setTotalsCollectedFlag', 'setCustomerEmail', 'getCustomerEmail', 'isVirtual',
        ]);
        $this->quote->method('isVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($this->shipping);
        $this->quote->method('getBillingAddress')->willReturn($this->billing);
        $this->quote->method('getCustomer')->willReturnCallback(fn () => $this->customer);
        $this->quote->method('setTotalsCollectedFlag')->willReturnSelf();
    }

    public function testApplyCopiesOnlyKnownFieldsAndRecollects(): void
    {
        $this->quote->expects($this->once())->method('collectTotals');
        $this->quote->expects($this->once())->method('setCustomerEmail')->with('guest@example.com');
        $this->repository->expects($this->once())->method('save')->with($this->quote);

        $this->updater()->apply($this->quote, [
            'firstname' => 'Ann',
            'street' => '5 Elm St',
            'postcode' => '',
            'country_id' => 'US',
            'email' => 'guest@example.com',
            'customer_id' => '999',
            'grand_total' => '0.01',
        ]);

        $this->assertSame('Ann', $this->shipping->getData('firstname'));
        $this->assertSame('5 Elm St', $this->shipping->getData('street'));
        $this->assertSame('US', $this->shipping->getData('country_id'));
        $this->assertNull($this->shipping->getData('postcode'), 'empty values are not applied');
        $this->assertNull($this->shipping->getData('customer_id'), 'unknown keys are ignored');
        $this->assertNull($this->shipping->getData('grand_total'));
        $this->assertTrue((bool) $this->shipping->getData('collect_shipping_rates'));
    }

    public function testArrayFieldsAndAnArrayEmailCountAsMissing(): void
    {
        $this->shipping->setData('lastname', 'Kept');
        $this->quote->method('getCustomerEmail')->willReturn('before@example.com');
        // email[]=1 used to raise "Array to string conversion" (a 500 on shippingmethods).
        $this->quote->expects($this->once())->method('setCustomerEmail')->with('before@example.com');

        $this->updater()->apply($this->quote, [
            'firstname' => 'Ann',
            'lastname' => ['x'],
            'street' => [['5 Elm St']],
            'company' => ['x'],
            'email' => ['x'],
        ]);

        $this->assertSame('Ann', $this->shipping->getData('firstname'));
        $this->assertSame('Kept', $this->shipping->getData('lastname'), 'an array keeps the value there was');
        $this->assertNull($this->shipping->getData('street'));
        $this->assertNull($this->shipping->getData('company'));
    }

    public function testApplyBillingIgnoresArrayFieldsAndEmail(): void
    {
        $this->quote->method('getCustomerEmail')->willReturn('before@example.com');

        $this->updater()->applyBilling($this->quote, [
            'billing_same_as_shipping' => '0',
            'billing_firstname' => ['x'],
            'billing_city' => 'Kyiv',
            'email' => ['x'],
        ]);

        $this->assertNull($this->billing->getData('firstname'));
        $this->assertSame('Kyiv', $this->billing->getData('city'));
        $this->assertSame('before@example.com', $this->billing->getData('email'));
    }

    public function testLoggedInCustomerEmailIsNotOverwritten(): void
    {
        $this->customer = $this->createStub(CustomerInterface::class);
        $this->customer->method('getId')->willReturn(12);
        $this->quote->expects($this->never())->method('setCustomerEmail');

        $this->updater()->apply($this->quote, ['email' => 'other@example.com']);
    }

    public function testBillingSameAsShippingReusesShippingValues(): void
    {
        $this->updater()->applyBilling($this->quote, [
            'firstname' => 'Ann',
            'city' => 'Austin',
            'billing_firstname' => 'Bob',
            'billing_city' => 'Dallas',
        ]);

        $this->assertSame('Ann', $this->billing->getData('firstname'));
        $this->assertSame('Austin', $this->billing->getData('city'));
    }

    public function testSeparateBillingUsesPrefixedFields(): void
    {
        $this->updater()->applyBilling($this->quote, [
            'billing_same_as_shipping' => '0',
            'firstname' => 'Ann',
            'billing_firstname' => 'Bob',
            'billing_street' => '9 Oak Ave',
        ]);

        $this->assertSame('Bob', $this->billing->getData('firstname'));
        $this->assertSame('9 Oak Ave', $this->billing->getData('street'));
    }

    public function testNewAddressWithSameBillingIsSavedOnceThroughBilling(): void
    {
        $this->loggedInWith([]);
        $this->typeAddress('Austin', 'Austin');

        $this->updater()->markAddressBookEntries($this->quote, true);

        $this->assertSame(1, $this->shipping->getSameAsBilling());
        $this->assertSame(0, $this->shipping->getSaveInAddressBook());
        $this->assertSame(1, $this->billing->getSaveInAddressBook());
    }

    public function testAddressMatchingTheBookIsLinkedNotSavedAgain(): void
    {
        $this->loggedInWith([$this->savedAddress(7, ' austin ')]);
        $this->typeAddress('Austin', 'Austin');

        $this->updater()->markAddressBookEntries($this->quote, true);

        $this->assertSame(7, $this->shipping->getCustomerAddressId());
        $this->assertSame(0, $this->shipping->getSaveInAddressBook());
        $this->assertSame(7, $this->billing->getCustomerAddressId());
        $this->assertSame(0, $this->billing->getSaveInAddressBook());
    }

    public function testSeparateBillingIsCheckedOnItsOwn(): void
    {
        $this->loggedInWith([$this->savedAddress(7, 'Austin')]);
        $this->typeAddress('Austin', 'Dallas');

        $this->updater()->markAddressBookEntries($this->quote, false);

        $this->assertSame(0, $this->shipping->getSameAsBilling());
        $this->assertSame(7, $this->shipping->getCustomerAddressId());
        $this->assertSame(1, $this->billing->getSaveInAddressBook());
    }

    public function testGuestAddressesAreNotFlagged(): void
    {
        $this->typeAddress('Austin', 'Austin');

        $this->updater()->markAddressBookEntries($this->quote, true);

        $this->assertNull($this->shipping->getSaveInAddressBook());
        $this->assertNull($this->billing->getSaveInAddressBook());
    }

    /**
     * @param AddressInterface[] $addresses
     */
    private function loggedInWith(array $addresses): void
    {
        $this->customer = $this->createStub(CustomerInterface::class);
        $this->customer->method('getId')->willReturn(12);
        $this->customer->method('getAddresses')->willReturn($addresses);
    }

    private function typeAddress(string $shippingCity, string $billingCity): void
    {
        $fields = [
            'firstname' => 'Ann', 'lastname' => 'Lee', 'street' => '5 Elm St', 'postcode' => '78701',
            'country_id' => 'US', 'telephone' => '5125550100',
        ];
        $this->shipping->addData($fields + ['city' => $shippingCity]);
        $this->billing->addData($fields + ['city' => $billingCity]);
    }

    private function savedAddress(int $id, string $city): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getId')->willReturn($id);
        $address->method('getFirstname')->willReturn('Ann');
        $address->method('getLastname')->willReturn('Lee');
        $address->method('getStreet')->willReturn(['5 Elm St']);
        $address->method('getCity')->willReturn($city);
        $address->method('getRegion')->willReturn($this->createStub(RegionInterface::class));
        $address->method('getPostcode')->willReturn('78701');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getTelephone')->willReturn('5125550100');

        return $address;
    }

    private function updater(): ShippingAddressUpdater
    {
        return new ShippingAddressUpdater($this->repository);
    }

    /**
     * A data-only address: setters/getters go through DataObject storage.
     */
    private function address(): Address
    {
        return $this->createPartialMock(Address::class, ['save']);
    }
}
