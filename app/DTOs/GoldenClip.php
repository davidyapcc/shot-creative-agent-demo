<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\JudgementVerdict;
use App\Services\Judge\JudgeRubric;
use RuntimeException;

/**
 * One golden clip and its human label.
 */
class GoldenClip
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $prompt,
        public readonly JudgementVerdict $verdict,
        public readonly array $tags,
    ) {}

    public static function fromArray(mixed $entry): self
    {
        if (! is_array($entry)) {
            throw new RuntimeException('Golden set has an entry that is not a clip.');
        }

        $id = $entry['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Golden set has a clip with no id.');
        }

        $path = $entry['path'] ?? null;
        $prompt = $entry['prompt'] ?? null;

        if (! is_string($path) || $path === '' || ! is_string($prompt) || $prompt === '') {
            throw new RuntimeException("Golden clip {$id} is missing a path or prompt.");
        }

        return new self(
            id: $id,
            path: $path,
            prompt: $prompt,
            verdict: self::verdict($id, $entry['verdict'] ?? null),
            tags: self::tags($id, $entry['tags'] ?? null),
        );
    }

    private static function verdict(string $id, mixed $verdict): JudgementVerdict
    {
        $parsed = is_string($verdict) ? JudgementVerdict::tryFrom($verdict) : null;

        if ($parsed === null) {
            throw new RuntimeException("Golden clip {$id} has an unknown verdict [".self::shown($verdict).'].');
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private static function tags(string $id, mixed $tags): array
    {
        if (! is_array($tags)) {
            throw new RuntimeException("Golden clip {$id} has tags that are not a list.");
        }

        foreach ($tags as $tag) {
            if (! is_string($tag) || ! in_array($tag, JudgeRubric::HARD_FAIL_TAGS, true)) {
                throw new RuntimeException("Golden clip {$id} has an unknown tag [".self::shown($tag).'].');
            }
        }

        return array_values($tags);
    }

    private static function shown(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
    }
}
