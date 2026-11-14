<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShotState;
use App\Exceptions\BudgetExceededException;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Budget
{
    public const UNIT_COST = 1;

    public const FREE_COST = 0;

    public function __construct(
        private readonly DegradedCompletion $completion,
    ) {}

    /**
     * Charge one paid call. Calls to free and mock providers still cost one unit.
     */
    public function charge(Brief $brief, string $provider, string $operation, ?int $shotId = null): void
    {
        DB::transaction(function () use ($brief, $provider, $operation, $shotId): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $cap = (int) config('creative.budget_cap');

            if ($locked->budget_used >= $cap) {
                throw new BudgetExceededException("Brief {$brief->id} is at its provider budget cap.");
            }

            $locked->update([
                'budget_used' => $locked->budget_used + self::UNIT_COST,
                'metadata' => $this->withLedgerEntry($locked, $provider, $operation, self::UNIT_COST, $shotId),
            ]);
        });
    }

    /**
     * Meter a provider call that spends nothing, such as a render poll. It is
     * logged at zero cost and never triggers the budget stop. Returns false
     * when the shot is no longer live and the call must not run.
     */
    public function meterFreeShotCall(Shot $shot, string $provider, string $operation): bool
    {
        if ($shot->state === ShotState::Failed) {
            return false;
        }

        $isMetered = DB::transaction(function () use ($shot, $provider, $operation): bool {
            $locked = Brief::query()->whereKey($shot->brief_id)->lockForUpdate()->first();

            if ($locked === null) {
                return true;
            }

            if ($locked->isBudgetExhausted()) {
                return false;
            }

            $locked->update([
                'metadata' => $this->withLedgerEntry($locked, $provider, $operation, self::FREE_COST, $shot->id),
            ]);

            return true;
        });

        $shot->refresh();

        return $isMetered && $shot->state !== ShotState::Failed;
    }

    /**
     * Meter the call, or stop the brief when the cap is already reached.
     */
    public function chargeOrDegrade(Brief $brief, string $provider, string $operation, ?int $shotId = null): bool
    {
        try {
            $this->charge($brief, $provider, $operation, $shotId);
        } catch (BudgetExceededException) {
            $this->completion->finish($brief);

            return false;
        }

        $brief->refresh();

        return true;
    }

    /**
     * Charge one paid shot step. Returns false when the step must not run: the
     * shot already failed, the brief hit its budget stop (now or before), or the
     * stop failed this shot while the charge was being taken.
     */
    public function chargeShotStep(Shot $shot, string $provider, string $operation): bool
    {
        $shot->loadMissing('brief');

        if ($shot->state === ShotState::Failed || $shot->brief?->isBudgetExhausted()) {
            return false;
        }

        if ($shot->brief !== null && ! $this->chargeOrDegrade($shot->brief, $provider, $operation, $shot->id)) {
            return false;
        }

        $shot->refresh();

        return $shot->state !== ShotState::Failed;
    }

    /**
     * Give back a charge when the provider refused the request and spent nothing.
     */
    public function refund(Brief $brief, string $provider, string $operation): void
    {
        DB::transaction(function () use ($brief, $provider, $operation): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $ledger = $metadata['budget_ledger'] ?? [];
            $last = $ledger === [] ? null : $ledger[array_key_last($ledger)];

            $isRefundable = $locked->budget_used >= self::UNIT_COST
              && is_array($last)
              && $last['provider'] === $provider
              && $last['operation'] === $operation;

            if (! $isRefundable) {
                // Only the latest entry is refundable; another charge landed first.
                Log::warning('Budget refund skipped: the latest ledger entry is not this call.', [
                    'brief_id' => $brief->id,
                    'provider' => $provider,
                    'operation' => $operation,
                    'latest_entry' => $last,
                ]);

                return;
            }

            array_pop($ledger);
            $metadata['budget_ledger'] = $ledger;

            $locked->update([
                'budget_used' => $locked->budget_used - self::UNIT_COST,
                'metadata' => $metadata,
            ]);
        });

        $brief->refresh();
    }

    /**
     * Give back one shot step's charge when the provider refused it. Shot steps
     * run in parallel, so another shot's charge may have landed since; this
     * reverses the latest entry for this shot, wherever it sits in the ledger.
     */
    public function refundShotStep(Shot $shot, string $provider, string $operation): void
    {
        $shot->loadMissing('brief');

        if ($shot->brief === null) {
            return;
        }

        DB::transaction(function () use ($shot, $provider, $operation): void {
            $locked = Brief::query()->whereKey($shot->brief_id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $ledger = $metadata['budget_ledger'] ?? [];
            $index = $this->latestShotEntry($ledger, $shot->id, $provider, $operation);

            if ($index === null || $locked->budget_used < self::UNIT_COST) {
                Log::warning('Budget refund skipped: no ledger entry for this shot step.', [
                    'brief_id' => $shot->brief_id,
                    'shot_id' => $shot->id,
                    'provider' => $provider,
                    'operation' => $operation,
                ]);

                return;
            }

            $metadata['budget_ledger'] = array_values(array_filter(
                $ledger,
                fn (int $key): bool => $key !== $index,
                ARRAY_FILTER_USE_KEY,
            ));

            $locked->update([
                'budget_used' => $locked->budget_used - self::UNIT_COST,
                'metadata' => $metadata,
            ]);
        });

        $shot->brief->refresh();
    }

    /**
     * The brief's metadata with one more ledger entry.
     *
     * @return array<string, mixed>
     */
    private function withLedgerEntry(Brief $brief, string $provider, string $operation, int $cost, ?int $shotId): array
    {
        $entry = ['provider' => $provider, 'operation' => $operation, 'cost' => $cost];
        $metadata = $brief->metadata ?? [];

        return [
            ...$metadata,
            'budget_ledger' => [
                ...$metadata['budget_ledger'] ?? [],
                $shotId === null ? $entry : [...$entry, 'shot_id' => $shotId],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $ledger
     */
    private function latestShotEntry(array $ledger, int $shotId, string $provider, string $operation): ?int
    {
        foreach (array_reverse($ledger, true) as $index => $entry) {
            if (is_array($entry)
                && ($entry['shot_id'] ?? null) === $shotId
                && $entry['provider'] === $provider
                && $entry['operation'] === $operation) {
                return $index;
            }
        }

        return null;
    }
}
