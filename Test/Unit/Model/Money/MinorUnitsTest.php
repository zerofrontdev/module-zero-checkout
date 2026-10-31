<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\Money;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\Money\MinorUnits;

/**
 * Amounts in the smallest unit: ISO 4217 exponents and each provider's
 * exceptions, with the half-up rounding a float amount needs (1.005 is
 * 1.00499999… in binary and must still be 101 cents).
 */
class MinorUnitsTest extends TestCase
{
    /**
     * @return array<string, array{string, int, float, int}>
     */
    public static function isoCases(): array
    {
        return [
            'JPY has no decimals' => ['JPY', 0, 1000.0, 1000],
            'KWD has three' => ['KWD', 3, 1.5, 1500],
            'USD has two' => ['USD', 2, 12.34, 1234],
            'UAH (not listed) has two' => ['UAH', 2, 99.99, 9999],
            'CLF has four' => ['CLF', 4, 1.2345, 12345],
            'ISK has none in ISO' => ['ISK', 0, 5.0, 5],
        ];
    }

    #[DataProvider('isoCases')]
    public function testIsoTable(string $currency, int $decimals, float $amount, int $minor): void
    {
        $this->assertSame($decimals, MinorUnits::decimals($currency));
        $this->assertSame($minor, MinorUnits::toMinor($amount, $currency));
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function roundingCases(): array
    {
        return [
            '1.005' => [1.005, 101],
            '10.005' => [10.005, 1001],
            '2.675' => [2.675, 268],
            '8.325' => [8.325, 833],
            '0.1 + 0.2' => [0.1 + 0.2, 30],
            '-1.005' => [-1.005, -101],
        ];
    }

    #[DataProvider('roundingCases')]
    public function testHalfUpRoundingOfBinaryFloats(float $amount, int $minor): void
    {
        $this->assertSame($minor, MinorUnits::toMinor($amount, 'USD'));
    }

    /**
     * @return array<string, array{float, string, int}>
     */
    public static function stripeCases(): array
    {
        return [
            'JPY zero-decimal' => [1000.0, 'JPY', 1000],
            'KWD rounded to a multiple of 10' => [10.005, 'KWD', 10010],
            'KWD down to a multiple of 10' => [10.004, 'KWD', 10000],
            'ISK whole units with two decimals' => [5.4, 'ISK', 500],
            'ISK half up' => [5.5, 'ISK', 600],
            'UGX whole units with two decimals' => [7.0, 'UGX', 700],
            'HUF two decimals' => [10.45, 'HUF', 1045],
            'MGA zero-decimal at Stripe only' => [100.0, 'MGA', 100],
            'IQD falls back to ISO' => [1.0, 'IQD', 1000],
        ];
    }

    #[DataProvider('stripeCases')]
    public function testStripeOverrides(float $amount, string $currency, int $minor): void
    {
        $this->assertSame($minor, MinorUnits::toMinor($amount, $currency, MinorUnits::STRIPE));
    }

    public function testMgaIsTwoDecimalsInIso(): void
    {
        $this->assertSame(2, MinorUnits::decimals('MGA'));
        $this->assertSame(0, MinorUnits::decimals('MGA', MinorUnits::STRIPE));
    }

    /**
     * @return array<string, array{float, string, int}>
     */
    public static function adyenCases(): array
    {
        return [
            'CLP has two at Adyen' => [1000.0, 'CLP', 100000],
            'ISK has two at Adyen' => [5.0, 'ISK', 500],
            'IDR has none at Adyen' => [15000.0, 'IDR', 15000],
            'KWD has three' => [1.0, 'KWD', 1000],
            'CLF is not in the Adyen table: two' => [1.0, 'CLF', 100],
        ];
    }

    #[DataProvider('adyenCases')]
    public function testAdyenOverrides(float $amount, string $currency, int $minor): void
    {
        $this->assertSame($minor, MinorUnits::toMinor($amount, $currency, MinorUnits::ADYEN));
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function shortListCases(): array
    {
        return [
            'Klarna JPY' => [MinorUnits::KLARNA, 'JPY', 0],
            'Klarna KRW' => [MinorUnits::KLARNA, 'KRW', 0],
            'Klarna ISK' => [MinorUnits::KLARNA, 'ISK', 0],
            'Klarna KWD stays two' => [MinorUnits::KLARNA, 'KWD', 2],
            'Amazon JPY' => [MinorUnits::AMAZON, 'JPY', 0],
            'Amazon KRW two' => [MinorUnits::AMAZON, 'KRW', 2],
            'PayPal HUF' => [MinorUnits::PAYPAL, 'HUF', 0],
            'PayPal TWD' => [MinorUnits::PAYPAL, 'TWD', 0],
            'PayPal JPY' => [MinorUnits::PAYPAL, 'JPY', 0],
            'PayPal EUR' => [MinorUnits::PAYPAL, 'EUR', 2],
        ];
    }

    #[DataProvider('shortListCases')]
    public function testKlarnaAmazonPaypal(string $provider, string $currency, int $decimals): void
    {
        $this->assertSame($decimals, MinorUnits::decimals($currency, $provider));
        $this->assertSame(10 ** $decimals, MinorUnits::toMinor(1.0, $currency, $provider));
    }

    public function testFromMinorInverse(): void
    {
        $this->assertSame(10.01, MinorUnits::fromMinor(1001, 'USD'));
        $this->assertSame(1000.0, (float) MinorUnits::fromMinor(1000, 'JPY'));
        $this->assertSame(10.005, MinorUnits::fromMinor(10005, 'KWD'));
        $this->assertSame(1000.0, MinorUnits::fromMinor(100000, 'CLP', MinorUnits::ADYEN));
        foreach (['USD' => 12.34, 'KWD' => 1.234, 'CLF' => 1.2345, 'JPY' => 77.0] as $currency => $amount) {
            $this->assertSame(
                $amount,
                (float) MinorUnits::fromMinor(MinorUnits::toMinor($amount, $currency), $currency),
                $currency
            );
        }
    }

    public function testFormat(): void
    {
        $this->assertSame('1.01', MinorUnits::format(1.005, 'USD'));
        $this->assertSame('1000', MinorUnits::format(1000, 'JPY'));
        $this->assertSame('10.000', MinorUnits::format(10, 'KWD'));
        $this->assertSame('1234567.89', MinorUnits::format(1234567.891, 'EUR'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame(MinorUnits::decimals('JPY'), MinorUnits::decimals('jpy'));
        $this->assertSame(10010, MinorUnits::toMinor(10.005, 'kwd', MinorUnits::STRIPE));
        $this->assertSame(500, MinorUnits::toMinor(5.4, 'isk', MinorUnits::STRIPE));
        $this->assertSame(0, MinorUnits::decimals('huf', MinorUnits::PAYPAL));
        $this->assertSame('10.000', MinorUnits::format(10, 'Kwd'));
    }
}
