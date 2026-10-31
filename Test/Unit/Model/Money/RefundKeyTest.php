<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\Money;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\Money\RefundKey;

/**
 * The refund idempotency key comes from what survives a rolled-back credit
 * memo: the order number, the base amount refunded so far and this refund's
 * base amount. A retry of the same memo repeats it; another partial refund
 * changes it; it never exceeds the provider's length.
 */
class RefundKeyTest extends TestCase
{
    public function testSameMemoRetriedGivesSameKey(): void
    {
        $first = RefundKey::for($this->payment('100000001', 0.0), 10.0, 'zero-');
        $retry = RefundKey::for($this->payment('100000001', 0.0), 10.0, 'zero-');

        $this->assertSame('zero-100000001-r0-1000', $first);
        $this->assertSame($first, $retry);
    }

    public function testSecondPartialRefundDiffers(): void
    {
        $first = RefundKey::for($this->payment('100000001', 0.0), 10.0);
        $second = RefundKey::for($this->payment('100000001', 10.0), 10.0);

        $this->assertSame('100000001-r0-1000', $first);
        $this->assertSame('100000001-r1000-1000', $second);
    }

    public function testAmountsAreInTheBaseCurrencyMinorUnits(): void
    {
        $this->assertSame('7-r500-1500', RefundKey::for($this->payment('7', 0.5, 'KWD'), 1.5));
        $this->assertSame('7-r5-15', RefundKey::for($this->payment('7', 5.0, 'JPY'), 15.0));
    }

    public function testLongIncrementIsHashedTo64(): void
    {
        $increment = str_repeat('9', 70);
        $key = RefundKey::for($this->payment($increment, 0.0), 10.0, 'tabby-');

        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringStartsWith('tabby-', $key);
        $this->assertSame('tabby-' . sha1('tabby-' . $increment . '-r0-1000'), $key);
        $this->assertSame($key, RefundKey::for($this->payment($increment, 0.0), 10.0, 'tabby-'));
        $this->assertNotSame($key, RefundKey::for($this->payment($increment, 10.0), 10.0, 'tabby-'));
    }

    public function testAShorterProviderLimitIsKept(): void
    {
        $key = RefundKey::for($this->payment('100000001', 0.0), 10.0, 'mono-', 20);

        $this->assertSame(20, strlen($key));
        $this->assertSame('mono-' . substr(sha1('mono-100000001-r0-1000'), 0, 15), $key);
    }

    public function testAPrefixLongerThanTheLimitIsCapped(): void
    {
        $prefix = str_repeat('p', 100);
        $key = RefundKey::for($this->payment('100000001', 0.0), 10.0, $prefix, 20);

        $this->assertSame(20, strlen($key));
        $this->assertStringStartsWith(str_repeat('p', 12), $key);
        $this->assertStringNotContainsString(str_repeat('p', 13), $key);
    }

    private function payment(string $increment, float $refunded, string $currency = 'USD'): Payment
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn($increment);
        $order->method('getBaseCurrencyCode')->willReturn($currency);
        $payment = $this->createStub(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getBaseAmountRefunded')->willReturn($refunded);

        return $payment;
    }
}
