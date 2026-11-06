<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\JudgeInterface;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\JudgeVideoJob;
use App\Models\EvalRun;
use App\Services\Budget;
use App\Services\EvalBudget;
use App\Services\EvalMetrics;
use App\Services\GoldenSet;
use App\Services\Judge\JudgeRubric;
use App\Services\JudgeFactory;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class EvalRunCommand extends Command
{
    private const MAX_RATE_LIMIT_REFUSALS = 3;

    protected $signature = 'eval:run
        {--judge= : Judge driver, defaulting to JUDGE_PROVIDER}
        {--set=golden : Set to score}
        {--budget= : Cap in cost units, defaulting to one unit per clip}';

    protected $description = 'Score a judge against the golden set';

    public function handle(JudgeFactory $judges, EvalBudget $budget): int
    {
        $driver = $this->driver();

        if ($this->option('set') !== 'golden') {
            $this->error('Only the golden set can be scored.');

            return self::FAILURE;
        }

        try {
            $clips = GoldenSet::load(base_path('evals/golden.json'));
            $judge = $driver === 'replay'
                ? $judges->make('replay', base_path('fixtures/replay/eval-judge.json'))
                : $judges->make($driver);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        GoldenSet::seed($clips);

        $cap = $this->cap($clips);
        $run = EvalRun::query()->create([
            'judge_driver' => $driver,
            'judge_model' => $this->modelName($driver),
            'rubric_version' => (new JudgeRubric)->version(),
            'dataset' => 'golden',
            'status' => 'partial',
            'results' => [],
            'ledger' => [],
        ]);

        $results = [];
        $stoppedEarly = false;

        foreach ($clips as $clip) {
            $outcome = $this->judgeClip($judge, $budget, $run, $cap, $driver, $clip, $stoppedEarly);

            if ($outcome === null) {
                break;
            }

            $results[] = $outcome;
        }

        $hasError = false;

        foreach ($results as $result) {
            if ($result['verdict'] === 'error') {
                $hasError = true;
            }
        }

        $run->update([
            'status' => ($stoppedEarly || $hasError) ? 'partial' : 'complete',
            'results' => $results,
            'metrics' => EvalMetrics::score($results, $budget->spent($run)),
        ]);
        $run->refresh();

        if ($run->status === 'complete') {
            $this->writeReport($run);
        }

        $this->info("Eval run {$run->id} is {$run->status}.");

        return self::SUCCESS;
    }

    private function driver(): string
    {
        $driver = $this->option('judge');

        if (! is_string($driver) || $driver === '') {
            $driver = (string) config('creative.judge.provider', 'replay');
        }

        return $driver;
    }

    /**
     * @param  list<array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}>  $clips
     */
    private function cap(array $clips): int
    {
        $option = $this->option('budget');

        if ($option === null || $option === false || $option === '') {
            return count($clips) * Budget::UNIT_COST;
        }

        return (int) $option;
    }

    private function modelName(string $driver): string
    {
        return match ($driver) {
            'gemini' => (string) config('creative.judge.gemini_model'),
            'openai' => (string) config('creative.judge.openai_model'),
            default => 'replay',
        };
    }

    /**
     * @param  array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}  $clip
     * @return array<string, mixed>|null
     */
    private function judgeClip(
        JudgeInterface $judge,
        EvalBudget $budget,
        EvalRun $run,
        int $cap,
        string $driver,
        array $clip,
        bool &$stoppedEarly,
    ): ?array {
        $refusals = 0;

        while ($refusals < self::MAX_RATE_LIMIT_REFUSALS) {
            if (! $budget->charge($run, $cap, $driver, 'judgeVideo', $clip['id'])) {
                $stoppedEarly = true;

                return null;
            }

            $this->waitForJudgeSlot();

            try {
                $verdict = $judge->judgeVideo($clip['path'], $clip['prompt']);

                return [
                    'id' => $clip['id'],
                    'path' => $clip['path'],
                    'verdict' => $verdict->verdict()->value,
                    'tags' => array_values($verdict->hardFailTags),
                    'scores' => $verdict->scores,
                    'reason' => $verdict->reason,
                    'error' => null,
                    'human' => [
                        'verdict' => $clip['verdict'],
                        'tags' => $clip['tags'],
                    ],
                ];
            } catch (ProviderRateLimitedException $exception) {
                $budget->refund($run, $driver, 'judgeVideo', $clip['id']);
                $refusals++;

                if ($refusals >= self::MAX_RATE_LIMIT_REFUSALS) {
                    return $this->errorResult($clip, $exception->getMessage());
                }
            } catch (Throwable $exception) {
                return $this->errorResult($clip, $exception->getMessage());
            }
        }

        return $this->errorResult($clip, 'Judge was rate limited.');
    }

    /**
     * @param  array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}  $clip
     * @return array<string, mixed>
     */
    private function errorResult(array $clip, string $message): array
    {
        return [
            'id' => $clip['id'],
            'path' => $clip['path'],
            'verdict' => 'error',
            'tags' => [],
            'scores' => [],
            'reason' => null,
            'error' => $message,
            'human' => [
                'verdict' => $clip['verdict'],
                'tags' => $clip['tags'],
            ],
        ];
    }

    private function waitForJudgeSlot(): void
    {
        $name = JudgeVideoJob::RATE_LIMITER;
        $limiter = RateLimiter::limiter($name);

        if ($limiter === null) {
            return;
        }

        $limit = $limiter();

        if ($limit instanceof Unlimited) {
            return;
        }

        $key = md5($name.$limit->key);

        while (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            $wait = RateLimiter::availableIn($key);
            sleep($wait > 0 ? $wait : 1);
        }

        RateLimiter::hit($key, $limit->decaySeconds);
    }

    private function writeReport(EvalRun $run): void
    {
        $metrics = $run->metrics ?? [];
        $lines = [
            '# Eval report',
            '',
            '- Run: '.$run->id,
            '- Driver: '.$run->judge_driver,
            '- Model: '.$run->judge_model,
            '- Rubric: '.$run->rubric_version,
            '- Set: '.$run->dataset,
            '- Status: '.$run->status,
            '',
            '## Metrics',
            '',
            '- Precision: '.$this->formatMetric($metrics['precision'] ?? null),
            '- Recall: '.$this->formatMetric($metrics['recall'] ?? null),
            '- Judge pass rate: '.$this->formatMetric($metrics['judge_pass_rate'] ?? null),
            '- Cost units: '.($metrics['cost_units'] ?? 0),
            '',
            '## Per-tag recall',
            '',
        ];

        foreach ($metrics['per_tag_recall'] ?? [] as $tag => $recall) {
            $lines[] = '- '.$tag.': '.$this->formatMetric($recall);
        }

        $lines[] = '';
        $lines[] = '## Disagreements';
        $lines[] = '';

        foreach ($run->results ?? [] as $result) {
            if (! is_array($result) || ! $this->disagrees($result)) {
                continue;
            }

            $humanTags = implode(', ', $result['human']['tags'] ?? []);
            $judgeTags = implode(', ', $result['tags'] ?? []);
            $lines[] = '- '.$result['id'].': human '.$result['human']['verdict']
                .' ('.$humanTags.'), judge '.$result['verdict'].' ('.$judgeTags.')';
        }

        $directory = base_path('evals');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory.'/REPORT.md', implode("\n", $lines)."\n");
    }

    private function disagrees(array $result): bool
    {
        if (($result['verdict'] ?? null) === 'error') {
            return false;
        }

        $humanTags = $result['human']['tags'] ?? [];
        $judgeTags = $result['tags'] ?? [];
        sort($humanTags);
        sort($judgeTags);

        return ($result['verdict'] ?? null) !== ($result['human']['verdict'] ?? null)
            || $humanTags !== $judgeTags;
    }

    private function formatMetric(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 3, '.', '');
        }

        return 'n/a';
    }
}
