<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\DecimalAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class NonNegativeAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Invalid amount');

            return;
        }

        try {
            DecimalAmount::normalize($value);
        } catch (InvalidArgumentException) {
            $fail('Invalid amount');
        }
    }
}
