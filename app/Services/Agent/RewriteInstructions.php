<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class RewriteInstructions
{
    /**
     * The one instruction a rewrite gets: simplify the failure, or, once that
     * fix is already in the scene or a previous attempt, try a different one.
     * Every agent calls this, so the mock and the LLM get the same instruction.
     *
     * @param  list<array{prompt?: string, reason?: string|null}>  $previousAttempts
     */
    public static function for(string $reason, string $scene, array $previousAttempts): string
    {
        $simplify = self::simplify($reason);

        return self::alreadyTried($simplify, $scene, $previousAttempts)
            ? self::different($reason)
            : $simplify;
    }

    public static function simplify(string $reason): string
    {
        return 'Simplify only this failure: '.self::reason($reason).'. Keep the same scene.';
    }

    public static function different(string $reason): string
    {
        return 'Use a different simplification than before for: '.self::reason($reason).'. Keep the same scene.';
    }

    /**
     * @param  list<array{prompt?: string, reason?: string|null}>  $previousAttempts
     */
    private static function alreadyTried(string $fix, string $scene, array $previousAttempts): bool
    {
        $prompts = [$scene, ...array_column($previousAttempts, 'prompt')];

        foreach ($prompts as $prompt) {
            if (is_string($prompt) && $prompt !== '' && str_contains($prompt, $fix)) {
                return true;
            }
        }

        return false;
    }

    private static function reason(string $reason): string
    {
        return rtrim(trim($reason), '.');
    }
}
