<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\PlaceOrder;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\PlaceOrder\GuestCheckoutValidator;

class GuestCheckoutValidatorTest extends TestCase
{
    public function testAGuestIsStoppedWhenGuestCheckoutIsOff(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator(false)->validate($this->quote(null), []);
    }

    public function testAGuestPassesWhenGuestCheckoutIsOn(): void
    {
        $this->validator(true)->validate($this->quote(null), []);
        $this->addToAssertionCount(1);
    }

    public function testASignedInCustomerAlwaysPasses(): void
    {
        $helper = $this->createMock(CheckoutHelper::class);
        $helper->expects($this->never())->method('isAllowedGuestCheckout');
        (new GuestCheckoutValidator($helper))->validate($this->quote(7), []);
    }

    private function validator(bool $allowed): GuestCheckoutValidator
    {
        $helper = $this->createMock(CheckoutHelper::class);
        $helper->method('isAllowedGuestCheckout')->willReturn($allowed);

        return new GuestCheckoutValidator($helper);
    }

    private function quote(?int $customerId): Quote
    {
        // getCustomerId() is a magic getter: the mock keeps DataObject's own.
        $quote = $this->createPartialMock(Quote::class, []);
        $quote->setData('customer_id', $customerId);

        return $quote;
    }
}
