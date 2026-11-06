<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShotState;
use App\Traits\HasStateTransitions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Shot extends Model
{
    use HasFactory;
    use HasStateTransitions;

    protected $fillable = [
        'brief_id',
        'sequence',
        'prompt',
        'state',
        'keyframe_path',
        'video_path',
        'attempts',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'state' => ShotState::class,
            'sequence' => 'integer',
            'attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function judgements(): HasMany
    {
        return $this->hasMany(Judgement::class)->orderBy('id');
    }

    /**
     * Count one more run of a step ('image' or 'video'), in total and per step.
     *
     * @param  array<string, mixed>  $metadata  extra metadata to store in the same update
     */
    public function recordStepAttempt(string $step, array $metadata = []): void
    {
        $merged = array_merge($this->metadata ?? [], $metadata);
        $merged['step_attempts'][$step] = (int) ($merged['step_attempts'][$step] ?? 0) + 1;

        $this->update([
            'attempts' => (int) $this->attempts + 1,
            'metadata' => $merged,
        ]);
    }

    /**
     * Generations after the first image attempt and the first video attempt.
     * Without step_attempts, attempts minus the stages the shot reached.
     */
    public function generationRetries(): int
    {
        $steps = $this->metadata['step_attempts'] ?? null;
        if (is_array($steps)) {
            $image = (int) ($steps['image'] ?? 0);
            $video = (int) ($steps['video'] ?? 0);

            return max(0, $image - 1) + max(0, $video - 1);
        }

        return max(0, (int) $this->attempts - $this->stagesReached());
    }

    /**
     * A failed shot that kept its keyframe and ran again has entered video,
     * even when that attempt never stored a clip.
     */
    private function stagesReached(): int
    {
        $image = filled($this->keyframe_path) || in_array($this->state, [
            ShotState::JudgingImage,
            ShotState::GeneratingVideo,
            ShotState::JudgingVideo,
            ShotState::Done,
        ], true);

        $video = filled($this->video_path) || in_array($this->state, [
            ShotState::GeneratingVideo,
            ShotState::JudgingVideo,
            ShotState::Done,
        ], true);

        if (! $video && $this->state === ShotState::Failed && filled($this->keyframe_path) && (int) $this->attempts > 1) {
            $video = true;
        }

        if (! $image && ! $video && (int) $this->attempts > 0) {
            return 1;
        }

        return (int) $image + (int) $video;
    }

    /**
     * Move on after a provider call, unless the budget stop failed this shot
     * while the call was in flight. Returns false when the result must be dropped.
     */
    public function advanceUnlessStopped(ShotState $target): bool
    {
        $isAdvanced = DB::transaction(function () use ($target): bool {
            $locked = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === ShotState::Failed) {
                return false;
            }

            $locked->transitionTo($target);

            return true;
        });

        $this->refresh();

        return $isAdvanced;
    }
}
