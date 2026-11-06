<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvalRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-run cap and ledger for an eval run. Independent of a brief, so later
 * eval commands can meter judge calls the same way.
 */
class EvalBudget
{
    public function charge(EvalRun $run, int $cap, string $provider, string $operation, string $clipId): bool
    {
        $charged = false;

        DB::transaction(function () use ($run, $cap, $provider, $operation, $clipId, &$charged): void {
            $locked = EvalRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $ledger = $locked->ledger ?? [];

            if ($this->cost($ledger) >= $cap) {
                return;
            }

            $ledger[] = [
                'provider' => $provider,
                'operation' => $operation,
                'cost' => Budget::UNIT_COST,
                'clip_id' => $clipId,
            ];

            $locked->update(['ledger' => $ledger]);
            $charged = true;
        });

        $run->refresh();

        return $charged;
    }

    public function refund(EvalRun $run, string $provider, string $operation, string $clipId): void
    {
        DB::transaction(function () use ($run, $provider, $operation, $clipId): void {
            $locked = EvalRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $ledger = $locked->ledger ?? [];
            $index = $this->latestClipEntry($ledger, $provider, $operation, $clipId);

            if ($index === null) {
                Log::warning('Eval budget refund skipped: no ledger entry for this clip.', [
                    'eval_run_id' => $run->id,
                    'provider' => $provider,
                    'operation' => $operation,
                    'clip_id' => $clipId,
                ]);

                return;
            }

            $ledger = array_values(array_filter(
                $ledger,
                fn (int $key): bool => $key !== $index,
                ARRAY_FILTER_USE_KEY,
            ));

            $locked->update(['ledger' => $ledger]);
        });

        $run->refresh();
    }

    public function spent(EvalRun $run): int
    {
        $run->refresh();

        return $this->cost($run->ledger ?? []);
    }

    /**
     * @param  array<int, mixed>  $ledger
     */
    private function cost(array $ledger): int
    {
        $cost = 0;

        foreach ($ledger as $entry) {
            if (is_array($entry)) {
                $cost += (int) ($entry['cost'] ?? 0);
            }
        }

        return $cost;
    }

    /**
     * @param  array<int, mixed>  $ledger
     */
    private function latestClipEntry(array $ledger, string $provider, string $operation, string $clipId): ?int
    {
        foreach (array_reverse($ledger, true) as $index => $entry) {
            if (is_array($entry)
                && ($entry['clip_id'] ?? null) === $clipId
                && ($entry['provider'] ?? null) === $provider
                && ($entry['operation'] ?? null) === $operation) {
                return $index;
            }
        }

        return null;
    }
}
