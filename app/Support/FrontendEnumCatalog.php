<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;

/**
 * PHP enums exported for the Vue page.
 *
 * Write resources/js/pipeline-states.json with `php artisan frontend:export-states`.
 * FrontendEnumCatalogTest fails when the file drifts from that output.
 */
final class FrontendEnumCatalog
{
    /**
     * @return array{brief: array<string, string>, shot: array<string, string>, verdict: array<string, string>}
     */
    public static function toArray(): array
    {
        return [
            'brief' => self::map(BriefState::class),
            'shot' => self::map(ShotState::class),
            'verdict' => self::map(JudgementVerdict::class),
        ];
    }

    public static function toJson(): string
    {
        $encoded = json_encode(
            self::toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $indented = preg_replace_callback(
            '/^(?:    )+/m',
            fn (array $match): string => str_repeat('  ', intdiv(strlen($match[0]), 4)),
            $encoded,
        );

        return ($indented ?? $encoded)."\n";
    }

    /**
     * @param  class-string<BriefState|ShotState|JudgementVerdict>  $enum
     * @return array<string, string>
     */
    private static function map(string $enum): array
    {
        $mapped = [];

        foreach ($enum::cases() as $case) {
            $mapped[self::constKey($case->name)] = $case->value;
        }

        return $mapped;
    }

    private static function constKey(string $name): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);

        return strtoupper($snake ?? $name);
    }
}
