<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

class Telemetry
{
    private const WINDOW = 50;

    /**
     * Pipeline queues from config/horizon.php. Other queues are ops noise.
     *
     * @var list<string>
     */
    private const QUEUES = ['default', 'generate', 'poll', 'judge', 'audio'];

    public function __construct(private readonly RateLimitHitCounter $rateLimits) {}

    /**
     * @return array{
     *     pass_rate: float|null,
     *     top_failure_tags: list<array{tag: string, count: int}>,
     *     retries_per_brief: float|null,
     *     duration_p50_seconds: float|null,
     *     duration_p95_seconds: float|null,
     *     cost_units_per_brief: float|null,
     *     queue_depth: int,
     *     failed_jobs: int,
     *     rate_limit_hits: int
     * }
     */
    public function snapshot(): array
    {
        $judgements = $this->latestJudgements();
        $briefs = $this->latestCompletedBriefs();
        $horizon = $this->horizonIsUp();

        return [
            'pass_rate' => $this->passRate($judgements),
            'top_failure_tags' => $this->failureTags($judgements),
            'retries_per_brief' => $this->retriesPerBrief($briefs),
            'duration_p50_seconds' => $this->durationPercentile($briefs, 50),
            'duration_p95_seconds' => $this->durationPercentile($briefs, 95),
            'cost_units_per_brief' => $this->costPerBrief($briefs),
            'queue_depth' => $this->queueDepth($horizon),
            'failed_jobs' => $this->failedJobs($horizon),
            'rate_limit_hits' => $this->rateLimits->total(),
        ];
    }

    /**
     * @return Collection<int, Judgement>
     */
    private function latestJudgements(): Collection
    {
        return Judgement::query()
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get();
    }

    /**
     * @return Collection<int, Brief>
     */
    private function latestCompletedBriefs(): Collection
    {
        return Brief::query()
            ->with('shots')
            ->whereIn('state', [BriefState::Done, BriefState::Degraded])
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get();
    }

    /**
     * @param  Collection<int, Judgement>  $judgements
     */
    private function passRate(Collection $judgements): ?float
    {
        if ($judgements->isEmpty()) {
            return null;
        }

        $passes = $judgements->where('verdict', 'pass')->count();

        return round($passes / $judgements->count() * 100, 1);
    }

    /**
     * @param  Collection<int, Judgement>  $judgements
     * @return list<array{tag: string, count: int}>
     */
    private function failureTags(Collection $judgements): array
    {
        $counts = [];

        foreach ($judgements as $judgement) {
            if ($judgement->verdict !== 'fail') {
                continue;
            }

            foreach ($judgement->hard_fail_tags ?? [] as $tag) {
                if (! is_string($tag) || $tag === '') {
                    continue;
                }

                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        $ranked = [];
        foreach ($counts as $tag => $count) {
            $ranked[] = ['tag' => $tag, 'count' => $count];
        }

        usort($ranked, function (array $left, array $right): int {
            if ($left['count'] !== $right['count']) {
                return $right['count'] <=> $left['count'];
            }

            return $left['tag'] <=> $right['tag'];
        });

        return $ranked;
    }

    /**
     * @param  Collection<int, Brief>  $briefs
     */
    private function retriesPerBrief(Collection $briefs): ?float
    {
        if ($briefs->isEmpty()) {
            return null;
        }

        $retries = 0;
        foreach ($briefs as $brief) {
            foreach ($brief->shots as $shot) {
                $retries += $this->retriesFor($shot);
            }
        }

        return round($retries / $briefs->count(), 1);
    }

    /**
     * Retries are generations after the first of each stage. Without
     * step_attempts, subtract the image and video stages the shot reached.
     */
    private function retriesFor(Shot $shot): int
    {
        $steps = $shot->metadata['step_attempts'] ?? null;
        if (is_array($steps)) {
            $image = (int) ($steps['image'] ?? 0);
            $video = (int) ($steps['video'] ?? 0);

            return max(0, $image - 1) + max(0, $video - 1);
        }

        return max(0, (int) $shot->attempts - $this->stagesReached($shot));
    }

    private function stagesReached(Shot $shot): int
    {
        $image = filled($shot->keyframe_path) || in_array($shot->state, [
            ShotState::JudgingImage,
            ShotState::GeneratingVideo,
            ShotState::JudgingVideo,
            ShotState::Done,
        ], true);

        $video = filled($shot->video_path) || in_array($shot->state, [
            ShotState::GeneratingVideo,
            ShotState::JudgingVideo,
            ShotState::Done,
        ], true);

        if (! $image && ! $video && (int) $shot->attempts > 0) {
            return 1;
        }

        return (int) $image + (int) $video;
    }

    /**
     * Nearest-rank percentile of an observed duration, so SQLite and Postgres match.
     *
     * @param  Collection<int, Brief>  $briefs
     */
    private function durationPercentile(Collection $briefs, float $percentile): ?float
    {
        if ($briefs->isEmpty()) {
            return null;
        }

        $durations = [];
        foreach ($briefs as $brief) {
            $durations[] = (float) $brief->created_at->diffInSeconds($brief->completed_at, true);
        }

        sort($durations);
        $rank = (int) ceil(($percentile / 100) * count($durations));

        return (float) $durations[max(0, $rank - 1)];
    }

    /**
     * @param  Collection<int, Brief>  $briefs
     */
    private function costPerBrief(Collection $briefs): ?float
    {
        if ($briefs->isEmpty()) {
            return null;
        }

        $total = 0.0;
        foreach ($briefs as $brief) {
            $total += $this->costUnits($brief);
        }

        return round($total / $briefs->count(), 1);
    }

    private function costUnits(Brief $brief): float
    {
        $ledger = $brief->metadata['budget_ledger'] ?? null;
        if (! is_array($ledger)) {
            return (float) $brief->budget_used;
        }

        $sum = 0.0;
        foreach ($ledger as $entry) {
            if (is_array($entry)) {
                $sum += (float) ($entry['cost'] ?? 0);
            }
        }

        return $sum;
    }

    private function horizonIsUp(): bool
    {
        try {
            return app(MasterSupervisorRepository::class)->all() !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function queueDepth(bool $horizon): int
    {
        if ($horizon) {
            try {
                return $this->horizonQueueDepth();
            } catch (Throwable) {
                // Horizon's workload read failed. Use the queue driver.
            }
        }

        $depth = 0;
        foreach (self::QUEUES as $queue) {
            $depth += (int) Queue::size($queue);
        }

        return $depth;
    }

    private function horizonQueueDepth(): int
    {
        $depth = 0;
        foreach (app(WorkloadRepository::class)->get() as $queue) {
            $name = $queue['name'] ?? '';
            if (! in_array($name, self::QUEUES, true)) {
                continue;
            }

            $depth += (int) ($queue['length'] ?? 0);
        }

        return $depth;
    }

    private function failedJobs(bool $horizon): int
    {
        if ($horizon) {
            try {
                return (int) app(JobRepository::class)->countFailed();
            } catch (Throwable) {
                // Horizon cannot count failures. Use the failed_jobs table.
            }
        }

        return (int) DB::table('failed_jobs')->count();
    }
}
