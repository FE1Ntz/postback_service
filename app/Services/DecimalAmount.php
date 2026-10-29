<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class DecimalAmount
{
    private const MAX_INPUT_LENGTH = 128;

    private const MAX_INTEGER_DIGITS = 18;

    private const MAX_FRACTION_DIGITS = 8;

    public static function normalize(string $value): string
    {
        $parts = self::parseNumber($value);
        [$integer, $fraction] = self::expandExponent(
            $parts['integer'],
            $parts['fraction'],
            $parts['exponent'],
        );
        self::validateNormalizedAmount($integer, $fraction, $parts['negative']);

        return $integer.($fraction === '' ? '' : '.'.$fraction);
    }

    /** @return array{integer: string, fraction: string, exponent: int, negative: bool} */
    private static function parseNumber(string $value): array
    {
        // The raw-input limit is in bytes, before validating the ASCII number.
        if (strlen($value) > self::MAX_INPUT_LENGTH || Str::startsWith($value, '-+')) {
            throw new InvalidArgumentException('Invalid amount');
        }

        $negative = Str::startsWith($value, '-');
        if ($negative) {
            $value = Str::substr($value, 1);
        }

        if (Str::startsWith($value, '.')) {
            $value = '0'.$value;
        } elseif (Str::startsWith($value, '+.')) {
            $value = '+0'.Str::substr($value, 1);
        }

        if (! preg_match('/\A\+?(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d{1,3}))?\z/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid amount');
        }

        return [
            'integer' => $parts[1],
            'fraction' => $parts[2] ?? '',
            'exponent' => (int) ($parts[3] ?? 0),
            'negative' => $negative,
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function expandExponent(string $integer, string $fraction, int $exponent): array
    {
        $digits = $integer.$fraction;
        $point = Str::length($integer) + $exponent;

        if ($point < 0) {
            $digits = Str::repeat('0', -$point).$digits;
            $point = 0;
        }

        if ($point > Str::length($digits)) {
            $digits .= Str::repeat('0', $point - Str::length($digits));
        }

        return [
            Str::ltrim(Str::substr($digits, 0, $point), '0') ?: '0',
            Str::rtrim(Str::substr($digits, $point), '0'),
        ];
    }

    private static function validateNormalizedAmount(string $integer, string $fraction, bool $negative): void
    {
        $exceedsPrecision = Str::length($integer) > self::MAX_INTEGER_DIGITS
            || Str::length($fraction) > self::MAX_FRACTION_DIGITS;
        $isNegativeNonzero = $negative && ($integer !== '0' || $fraction !== '');

        if ($exceedsPrecision || $isNegativeNonzero) {
            throw new InvalidArgumentException('Amount outside supported precision');
        }
    }
}
