<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\JudgementVerdict;

/**
 * The judge's verdict on one golden clip, next to the human label. A null
 * verdict means the judge call errored and the clip is left out of metrics.
 */
class EvalClipResult
{
    public const ERROR_VERDICT = 'error';

    /**
     * @param  list<string>  $tags
     * @param  array<string, int>  $scores
     */
    private function __construct(
        public readonly GoldenClip $clip,
        public readonly ?JudgementVerdict $verdict,
        public readonly array $tags,
        public readonly array $scores,
        public readonly ?string $reason,
        public readonly ?string $error,
    ) {}

    public static function judged(GoldenClip $clip, JudgementResult $judgement): self
    {
        return new self(
            clip: $clip,
            verdict: $judgement->verdict(),
            tags: array_values($judgement->hardFailTags),
            scores: $judgement->scores,
            reason: $judgement->reason,
            error: null,
        );
    }

    public static function errored(GoldenClip $clip, string $message): self
    {
        return new self(
            clip: $clip,
            verdict: null,
            tags: [],
            scores: [],
            reason: null,
            error: $message,
        );
    }

    public function isError(): bool
    {
        return $this->verdict === null;
    }

    public function judgeFailed(): bool
    {
        return $this->verdict === JudgementVerdict::Fail;
    }

    public function humanFailed(): bool
    {
        return $this->clip->verdict === JudgementVerdict::Fail;
    }

    public function judgeTagged(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function humanTagged(string $tag): bool
    {
        return in_array($tag, $this->clip->tags, true);
    }

    /**
     * True when the judge's verdict or tags differ from the human label.
     */
    public function disagreesWithHuman(): bool
    {
        if ($this->isError()) {
            return false;
        }

        $humanTags = $this->clip->tags;
        $judgeTags = $this->tags;
        sort($humanTags);
        sort($judgeTags);

        return $this->verdict !== $this->clip->verdict || $humanTags !== $judgeTags;
    }

    public function verdictLabel(): string
    {
        return $this->verdict->value ?? self::ERROR_VERDICT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->clip->id,
            'path' => $this->clip->path,
            'verdict' => $this->verdictLabel(),
            'tags' => $this->tags,
            'scores' => $this->scores,
            'reason' => $this->reason,
            'error' => $this->error,
            'human' => [
                'verdict' => $this->clip->verdict->value,
                'tags' => $this->clip->tags,
            ],
        ];
    }
}
