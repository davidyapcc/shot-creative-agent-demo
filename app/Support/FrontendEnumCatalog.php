<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;

/**
 * PHP enums exported for the Vue page.
 *
 * resources/js/pipeline-states.json is generated from this catalog.
 * FrontendEnumCatalogTest fails when the file drifts.
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

  /**
   * @param class-string<BriefState|ShotState|JudgementVerdict> $enum
   * @return array<string, string>
   */
  private static function map(string $enum): array
  {
    $mapped = [];

    foreach ($enum::cases() as $case)
    {
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
