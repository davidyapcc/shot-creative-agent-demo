<?php

declare(strict_types=1);

namespace App\Services\Judge;

use App\DTOs\JudgementResult;
use RuntimeException;

/**
 * The one rubric both judge drivers send and check, so their scores cannot drift.
 */
class JudgeRubric
{
    public const IMAGE_DIMENSIONS = [
        'prompt_adherence',
        'anatomy',
        'object_integrity',
    ];

    public const VIDEO_DIMENSIONS = [
        'prompt_adherence',
        'anatomy',
        'object_integrity',
        'temporal_consistency',
    ];

    public const HARD_FAIL_TAGS = [
        'anatomy',
        'scene_mismatch',
        'object_warp',
        'temporal_flicker',
        'text_artifact',
    ];

    private const CHECKS = [
        'prompt_adherence' => 'Matches the brief and shot plan.',
        'anatomy' => 'Hands, faces, and limbs are correct.',
        'object_integrity' => 'Objects keep their shape (no warped wings, melted props).',
        'temporal_consistency' => 'No flicker or morphing between frames.',
    ];

    private const MIN_SCORE = 1;

    private const MAX_SCORE = 5;

    /**
     * Stable hash of the rubric text and schema a judge sends.
     */
    public function version(): string
    {
        return hash('sha256', json_encode([
            'image' => self::IMAGE_DIMENSIONS,
            'video' => self::VIDEO_DIMENSIONS,
            'tags' => self::HARD_FAIL_TAGS,
            'checks' => self::CHECKS,
            'min_score' => self::MIN_SCORE,
            'max_score' => self::MAX_SCORE,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    public function dimensions(bool $video): array
    {
        return $video ? self::VIDEO_DIMENSIONS : self::IMAGE_DIMENSIONS;
    }

    /**
     * JSON Schema for structured output. Image scores omit temporal consistency.
     *
     * @return array<string, mixed>
     */
    public function schema(bool $video): array
    {
        $dimensions = $this->dimensions($video);
        $properties = [];

        foreach ($dimensions as $dimension) {
            $properties[$dimension] = [
                'type' => 'integer',
                'minimum' => self::MIN_SCORE,
                'maximum' => self::MAX_SCORE,
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scores', 'hard_fail_tags', 'reason'],
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $dimensions,
                    'properties' => $properties,
                ],
                'hard_fail_tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::HARD_FAIL_TAGS,
                    ],
                ],
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    public function prompt(bool $video, string $shotPrompt, ?string $styleAnchor, ?int $sampledFps = null): string
    {
        $lines = ['Score this shot from 1 to 5 on each dimension.'];

        foreach ($this->dimensions($video) as $dimension) {
            $lines[] = $dimension.': '.self::CHECKS[$dimension];
        }

        $lines[] = 'Set a hard-fail tag only when that failure is present. Allowed tags: '.implode(', ', self::HARD_FAIL_TAGS).'.';

        if ($sampledFps !== null) {
            $lines[] = "The images are consecutive frames sampled at {$sampledFps} fps, in time order. Score temporal consistency across that sequence.";
        }

        $lines[] = 'Shot plan: '.$shotPrompt;

        if (is_string($styleAnchor) && $styleAnchor !== '') {
            $lines[] = 'Style anchor: '.$styleAnchor;
        }

        return implode("\n", $lines);
    }

    /**
     * Reject a partial or unreadable response before a verdict is built.
     * The model does not decide the verdict; JudgementResult applies the pass rule.
     */
    public function parse(string $payload, bool $video): JudgementResult
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Judge response is not valid JSON.');
        }

        $scores = $decoded['scores'] ?? null;

        if (! is_array($scores)) {
            throw new RuntimeException('Judge response is missing scores.');
        }

        $dimensions = $this->dimensions($video);
        $parsedScores = [];

        foreach ($dimensions as $dimension) {
            if (! array_key_exists($dimension, $scores)) {
                throw new RuntimeException("Judge response is missing the {$dimension} score.");
            }

            $score = $scores[$dimension];

            if (! is_int($score) || $score < self::MIN_SCORE || $score > self::MAX_SCORE) {
                throw new RuntimeException("Judge score for {$dimension} must be an integer from 1 to 5.");
            }

            $parsedScores[$dimension] = $score;
        }

        foreach (array_keys($scores) as $dimension) {
            if (! in_array($dimension, $dimensions, true)) {
                throw new RuntimeException("Judge response has an unknown score [{$dimension}].");
            }
        }

        $tags = $decoded['hard_fail_tags'] ?? null;

        if (! is_array($tags)) {
            throw new RuntimeException('Judge response is missing hard_fail_tags.');
        }

        foreach ($tags as $tag) {
            if (! is_string($tag) || ! in_array($tag, self::HARD_FAIL_TAGS, true)) {
                throw new RuntimeException('Judge response has an unknown hard-fail tag.');
            }
        }

        $reason = $decoded['reason'] ?? null;

        if (! is_string($reason)) {
            throw new RuntimeException('Judge response is missing a reason.');
        }

        return new JudgementResult(
            scores: $parsedScores,
            hardFailTags: array_values($tags),
            reason: $reason,
        );
    }
}
