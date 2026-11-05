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

    public function __construct(
        private readonly DegradedCompletion $completion,
    ) {}

    /**
     * Record one provider call. Free and mock calls cost one unit.
     */
    public function charge(Brief $brief, string $provider, string $operation): void
    {
        DB::transaction(function () use ($brief, $provider, $operation): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $cap = (int) config('creative.budget_cap');

            if ($locked->budget_used >= $cap) {
                throw new BudgetExceededException("Brief {$brief->id} is at its provider budget cap.");
            }

            $metadata = $locked->metadata ?? [];
            $ledger = $metadata['budget_ledger'] ?? [];
            $ledger[] = [
                'provider' => $provider,
                'operation' => $operation,
                'cost' => self::UNIT_COST,
            ];
            $metadata['budget_ledger'] = $ledger;

            $locked->update([
                'budget_used' => $locked->budget_used + self::UNIT_COST,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Meter the call, or stop the brief when the cap is already reached.
     */
    public function chargeOrDegrade(Brief $brief, string $provider, string $operation): bool
    {
        try {
            $this->charge($brief, $provider, $operation);
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

        if ($shot->brief !== null && ! $this->chargeOrDegrade($shot->brief, $provider, $operation)) {
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
}
