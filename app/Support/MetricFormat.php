<?php

declare(strict_types=1);

namespace App\Support;

class MetricFormat
{
    private const DECIMALS = 3;

    public const NOT_APPLICABLE = 'n/a';

    public static function format(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, self::DECIMALS, '.', '');
        }

        return self::NOT_APPLICABLE;
    }

    public static function delta(mixed $left, mixed $right): ?string
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return null;
        }

        return sprintf('%+.'.self::DECIMALS.'f', (float) $right - (float) $left);
    }
}
