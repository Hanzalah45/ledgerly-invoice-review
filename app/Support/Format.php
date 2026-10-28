<?php

namespace App\Support;

use Illuminate\Support\Number;

class Format
{
    public static function money(mixed $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        if ($currency && preg_match('/^[A-Z]{3}$/', $currency)) {
            return Number::currency((float) $amount, $currency) ?: number_format((float) $amount, 2);
        }

        return number_format((float) $amount, 2);
    }

    public static function number(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $formatted = number_format((float) $value, $decimals);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /** @return string high|mid|low */
    public static function confidenceLevel(?float $value): string
    {
        return match (true) {
            $value === null => 'none',
            $value >= 0.85 => 'high',
            $value >= (float) config('docai.low_confidence') => 'mid',
            default => 'low',
        };
    }
}
