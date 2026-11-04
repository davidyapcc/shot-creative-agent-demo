<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BudgetExceededException;
use App\Models\Brief;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Budget
{
  public function __construct(
    private readonly DegradedCompletion $completion,
  )
  {
  }

  /**
   * Record one provider call. Free and mock calls cost one unit.
   */
  public function charge(Brief $brief, string $provider, string $operation): void
  {
    DB::transaction(function () use ($brief, $provider, $operation): void
    {
      $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
      $cap = (int) config('creative.budget_cap', 100);

      if ($locked->budget_used >= $cap)
      {
        throw new BudgetExceededException("Brief {$brief->id} is at its provider budget cap.");
      }

      $metadata = $locked->metadata ?? [];
      $ledger = $metadata['budget_ledger'] ?? [];
      $ledger[] = [
        'provider' => $provider,
        'operation' => $operation,
        'cost' => 1,
      ];
      $metadata['budget_ledger'] = $ledger;

      $locked->update([
        'budget_used' => $locked->budget_used + 1,
        'metadata' => $metadata,
      ]);
    });
  }

  /**
   * Meter the call, or stop the brief when the cap is already reached.
   */
  public function chargeOrDegrade(Brief $brief, string $provider, string $operation): bool
  {
    try
    {
      $this->charge($brief, $provider, $operation);
    }
    catch (BudgetExceededException)
    {
      $this->completion->finish($brief);

      return false;
    }

    $brief->refresh();

    return true;
  }

  /**
   * Give back a charge when the provider refused the request and spent nothing.
   */
  public function refund(Brief $brief, string $provider, string $operation): void
  {
    DB::transaction(function () use ($brief, $provider, $operation): void
    {
      $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
      $metadata = $locked->metadata ?? [];
      $ledger = $metadata['budget_ledger'] ?? [];
      $last = $ledger === [] ? null : $ledger[array_key_last($ledger)];

      $isRefundable = $locked->budget_used >= 1
        && is_array($last)
        && $last['provider'] === $provider
        && $last['operation'] === $operation;

      if (!$isRefundable)
      {
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
        'budget_used' => $locked->budget_used - 1,
        'metadata' => $metadata,
      ]);
    });

    $brief->refresh();
  }
}
