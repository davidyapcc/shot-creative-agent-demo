<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class RewriteInstructions
{
    public static function simplify(string $reason): string
    {
        return 'Simplify only this failure: '.self::reason($reason).'. Keep the same scene.';
    }

    public static function different(string $reason): string
    {
        return 'Use a different simplification than before for: '.self::reason($reason).'. Keep the same scene.';
    }

    private static function reason(string $reason): string
    {
        return rtrim(trim($reason), '.');
    }
}
