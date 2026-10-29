<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DecimalAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalAmountTest extends TestCase
{
    public function test_deterministic_generated_numeric_equivalents_and_idempotence(): void
    {
        for ($index = 0; $index < 200; $index++) {
            $whole = (string) (($index * 7919) % 1000000);
            $fraction = str_pad((string) (($index * 104729) % 100000000), 8, '0', STR_PAD_LEFT);
            $canonicalFraction = rtrim($fraction, '0');
            $expected = $whole.($canonicalFraction === '' ? '' : '.'.$canonicalFraction);
            foreach ([$whole.'.'.$fraction, '+000'.$whole.'.'.$fraction.'000', $whole.$fraction.'e-8', $expected] as $input) {
                $actual = DecimalAmount::normalize($input);
                self::assertSame($expected, $actual, $input);
                self::assertSame($actual, DecimalAmount::normalize($actual));
            }
        }
    }

    #[DataProvider('equivalentAmounts')]
    public function test_normalizes_without_float_rounding(string $input, string $expected): void
    {
        self::assertSame($expected, DecimalAmount::normalize($input));
    }

    public static function equivalentAmounts(): array
    {
        return [['.5', '0.5'], ['+.5', '0.5'], ['-0', '0'], ['49.90', '49.9'], ['0049.900', '49.9'], ['4.990e1', '49.9'],
            ['0', '0'], [str_repeat('0', 128), '0'], ['.'.str_repeat('0', 127), '0'], ['+.'.str_repeat('0', 126), '0'], ['0.00000000', '0'], ['1e-8', '0.00000001'],
            ['999999999999999999.99999999', '999999999999999999.99999999'],
            ['9007199254740993.01', '9007199254740993.01']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_unsupported_values(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalAmount::normalize($input);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['', '-1', '-+0', '-'.str_repeat('0', 128), 'NaN', 'INF', '1e999999',
            '1000000000000000000', '1e-9', '1.234567891', str_repeat('0', 129), '1.2.3']);
    }
}
