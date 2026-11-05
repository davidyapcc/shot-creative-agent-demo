<?php

declare(strict_types=1);

namespace App\DTOs;

use InvalidArgumentException;

/**
 * A validated Plan Shots result: 2 or 3 shots in sequence order, plus the style
 * anchor every keyframe shares. Every agent's plan passes through here, so the
 * mock and the real LLM are held to the same rules.
 */
final class ShotPlan
{
    private const MIN_SHOTS = 2;

    private const MAX_SHOTS = 3;

    public readonly string $styleAnchor;

    /**
     * @var list<array{sequence: int, prompt: string}>
     */
    public readonly array $shots;

    /**
     * Takes untrusted values (an LLM's structured output) and rejects anything
     * that is not a usable plan.
     */
    public function __construct(mixed $styleAnchor, mixed $shots)
    {
        $this->styleAnchor = self::validatedAnchor($styleAnchor);
        $this->shots = self::validatedShots($shots);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): self
    {
        return new self($structured['style_anchor'] ?? null, $structured['shots'] ?? null);
    }

    private static function validatedAnchor(mixed $anchor): string
    {
        if (! is_string($anchor) || trim($anchor) === '') {
            throw new InvalidArgumentException('Agent plan needs a style anchor.');
        }

        return trim($anchor);
    }

    /**
     * @return list<array{sequence: int, prompt: string}>
     */
    private static function validatedShots(mixed $shots): array
    {
        if (! is_array($shots) || ! array_is_list($shots)
            || count($shots) < self::MIN_SHOTS || count($shots) > self::MAX_SHOTS) {
            throw new InvalidArgumentException('Agent plan must contain 2 or 3 shots.');
        }

        $planned = array_map(self::validatedShot(...), $shots);
        usort($planned, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        if (array_column($planned, 'sequence') !== range(1, count($planned))) {
            throw new InvalidArgumentException('Shot sequences must run 1 to n with no gaps or repeats.');
        }

        return $planned;
    }

    /**
     * @return array{sequence: int, prompt: string}
     */
    private static function validatedShot(mixed $shot): array
    {
        $sequence = is_array($shot) ? ($shot['sequence'] ?? null) : null;
        $prompt = is_array($shot) ? ($shot['prompt'] ?? null) : null;

        if (! is_int($sequence) || $sequence < 1 || ! is_string($prompt) || trim($prompt) === '') {
            throw new InvalidArgumentException('Each shot needs a sequence and a prompt.');
        }

        return [
            'sequence' => $sequence,
            'prompt' => trim($prompt),
        ];
    }
}
