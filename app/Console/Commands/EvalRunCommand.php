<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\JudgeInterface;
use App\DTOs\EvalClipResult;
use App\DTOs\GoldenClip;
use App\Enums\EvalRunStatus;
use App\Models\EvalRun;
use App\Services\Budget;
use App\Services\EvalBudget;
use App\Services\EvalMetrics;
use App\Services\EvalReport;
use App\Services\GoldenSet;
use App\Services\Judge\JudgeRubric;
use App\Services\JudgeFactory;
use App\Services\MeteredClipJudge;
use App\Services\Providers\ReplayJudge;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class EvalRunCommand extends Command
{
    private const MIN_BUDGET = 1;

    protected $signature = 'eval:run
        {--judge= : Judge driver, defaulting to JUDGE_PROVIDER}
        {--set=golden : Set to score}
        {--budget= : Cap in cost units, defaulting to one unit per clip}';

    protected $description = 'Score a judge against the golden set';

    public function handle(JudgeFactory $judges, EvalBudget $budget, EvalReport $report): int
    {
        if ($this->option('set') !== GoldenSet::NAME) {
            $this->error('Only the golden set can be scored.');

            return self::FAILURE;
        }

        $driver = $this->driver();

        try {
            $clips = GoldenSet::load(base_path('evals/golden.json'));
            $cap = $this->cap(count($clips));
            $judge = $this->judge($judges, $driver, $clips);
            GoldenSet::seed($clips);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            // Setup failures stop the command before an eval run exists or anything is charged.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $run = $this->createRun($driver);
        $results = $this->judgeAll(new MeteredClipJudge($judge, $budget, $run, $cap, $driver), $clips);
        $this->finish($run, $results, count($clips), $budget->spent($run));

        if ($run->status === EvalRunStatus::Complete) {
            $report->write($run, $results, base_path('evals/REPORT.md'));
        }

        $this->info("Eval run {$run->id} is {$run->status->value}.");

        return self::SUCCESS;
    }

    private function driver(): string
    {
        $driver = $this->option('judge');

        if (! is_string($driver) || $driver === '') {
            return (string) config('creative.judge.provider', 'replay');
        }

        return $driver;
    }

    private function cap(int $clipCount): int
    {
        $option = $this->option('budget');

        if ($option === null || $option === '') {
            return $clipCount * Budget::UNIT_COST;
        }

        $cap = filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_BUDGET]]);

        if ($cap === false) {
            throw new InvalidArgumentException(
                '--budget must be a whole number of at least '.self::MIN_BUDGET.", [{$option}] given.",
            );
        }

        return $cap;
    }

    /**
     * @param  list<GoldenClip>  $clips
     */
    private function judge(JudgeFactory $judges, string $driver, array $clips): JudgeInterface
    {
        if ($driver !== 'replay') {
            return $judges->make($driver);
        }

        $judge = $judges->make('replay', base_path('fixtures/replay/eval-judge.json'));

        if ($judge instanceof ReplayJudge) {
            $judge->assertRecorded(array_map(fn (GoldenClip $clip): string => $clip->path, $clips));
        }

        return $judge;
    }

    private function createRun(string $driver): EvalRun
    {
        return EvalRun::query()->create([
            'judge_driver' => $driver,
            'judge_model' => $this->modelName($driver),
            'rubric_version' => (new JudgeRubric)->version(),
            'dataset' => GoldenSet::NAME,
            'status' => EvalRunStatus::Partial,
            'results' => [],
            'ledger' => [],
        ]);
    }

    private function modelName(string $driver): string
    {
        return match ($driver) {
            'gemini' => (string) config('creative.judge.gemini_model'),
            'openai' => (string) config('creative.judge.openai_model'),
            default => $driver,
        };
    }

    /**
     * Judge clips in order until they run out or the budget does.
     *
     * @param  list<GoldenClip>  $clips
     * @return list<EvalClipResult>
     */
    private function judgeAll(MeteredClipJudge $judge, array $clips): array
    {
        $results = [];

        foreach ($clips as $clip) {
            $result = $judge->judge($clip);

            if ($result === null) {
                break;
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  list<EvalClipResult>  $results
     */
    private function finish(EvalRun $run, array $results, int $clipCount, int $spent): void
    {
        $judgedEveryClip = count($results) === $clipCount;
        $hasError = array_filter($results, fn (EvalClipResult $result): bool => $result->isError()) !== [];

        $run->update([
            'status' => ($judgedEveryClip && ! $hasError) ? EvalRunStatus::Complete : EvalRunStatus::Partial,
            'results' => array_map(fn (EvalClipResult $result): array => $result->toArray(), $results),
            'metrics' => EvalMetrics::score($results, $spent),
        ]);
    }
}
