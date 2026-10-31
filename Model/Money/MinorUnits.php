<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\Money;

/**
 * An amount in a currency's smallest unit (and back), for the payment
 * providers that want it: ISO 4217 exponents, with each provider's own
 * exceptions. Pure and static: no state, no DI.
 *
 * - ISO: 0 digits for BIF CLP DJF GNF ISK JPY KMF KRW PYG RWF UGX UYI VND VUV XAF XOF XPF,
 *   3 for BHD IQD JOD KWD LYD OMR TND, 4 for CLF, 2 otherwise;
 * - STRIPE: ISK and UGX are sent as two-decimal amounts of whole units (500 for ISK 5),
 *   the three-decimal currencies must be a multiple of 10, the rest as ISO except that
 *   MGA is zero-decimal;
 * - ADYEN: its own table (CLP and ISK have 2 decimals there);
 * - KLARNA, AMAZON, PAYPAL: a short list of zero-decimal currencies, 2 for all else.
 */
class MinorUnits
{
    public const ISO = 'iso';
    public const STRIPE = 'stripe';
    public const ADYEN = 'adyen';
    public const KLARNA = 'klarna';
    public const AMAZON = 'amazon';
    public const PAYPAL = 'paypal';

    private const ISO_ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const ISO_THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];
    private const ISO_FOUR = ['CLF'];

    private const STRIPE_ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    /** Whole units, written with two decimals (docs.stripe.com/currencies, "Special cases"). */
    private const STRIPE_TWO_FOR_WHOLE = ['ISK', 'UGX'];
    /** Three decimals, of which the last must be 0. To confirm in the sandbox. */
    private const STRIPE_THREE = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    private const ADYEN_ZERO = ['CVE', 'DJF', 'GNF', 'IDR', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const ADYEN_THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private const SHORT_ZERO = [
        self::KLARNA => ['JPY', 'KRW', 'ISK'],
        self::AMAZON => ['JPY'],
        self::PAYPAL => ['HUF', 'JPY', 'TWD'],
    ];

    /**
     * Digits after the decimal point of the amount the provider is given.
     */
    public static function decimals(string $currency, string $provider = self::ISO): int
    {
        $currency = strtoupper($currency);
        switch ($provider) {
            case self::STRIPE:
                if (in_array($currency, self::STRIPE_ZERO, true)) {
                    return 0;
                }
                if (in_array($currency, self::STRIPE_TWO_FOR_WHOLE, true)) {
                    return 2;
                }
                if (in_array($currency, self::STRIPE_THREE, true)) {
                    return 3;
                }
                return self::iso($currency);
            case self::ADYEN:
                if (in_array($currency, self::ADYEN_ZERO, true)) {
                    return 0;
                }
                return in_array($currency, self::ADYEN_THREE, true) ? 3 : 2;
            case self::KLARNA:
            case self::AMAZON:
            case self::PAYPAL:
                return in_array($currency, self::SHORT_ZERO[$provider], true) ? 0 : 2;
            default:
                return self::iso($currency);
        }
    }

    public static function toMinor(float $amount, string $currency, string $provider = self::ISO): int
    {
        $currency = strtoupper($currency);
        if ($provider === self::STRIPE && in_array($currency, self::STRIPE_TWO_FOR_WHOLE, true)) {
            return (int) round($amount) * 100;
        }
        $digits = self::decimals($currency, $provider);
        $minor = (int) round(round($amount, $digits) * 10 ** $digits);
        if ($provider === self::STRIPE && in_array($currency, self::STRIPE_THREE, true)) {
            return (int) (round($minor / 10) * 10);
        }

        return $minor;
    }

    public static function fromMinor(int $minor, string $currency, string $provider = self::ISO): float
    {
        return $minor / 10 ** self::decimals($currency, $provider);
    }

    /**
     * The amount as a decimal string with the currency's digits ("1.01", "1000", "10.000").
     */
    public static function format(float $amount, string $currency, string $provider = self::ISO): string
    {
        return number_format($amount, self::decimals($currency, $provider), '.', '');
    }

    private static function iso(string $currency): int
    {
        if (in_array($currency, self::ISO_ZERO, true)) {
            return 0;
        }
        if (in_array($currency, self::ISO_THREE, true)) {
            return 3;
        }

        return in_array($currency, self::ISO_FOUR, true) ? 4 : 2;
    }
}
