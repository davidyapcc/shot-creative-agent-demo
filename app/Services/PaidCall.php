<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Models\Shot;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one owner of a paid call. Every shot step and brief step runs through
 * here, in this order:
 *
 *  1. charge first; a refused charge never reaches the provider
 *  2. take the claim, where the step has one; a lost claim refunds the charge
 *  3. call the provider
 *  4. on a 429: refund, release the claim, count the hit, rethrow
 *  5. on any other failure: keep the charge and the claim, rethrow
 *  6. re-check under a row lock that the shot or brief is still live
 *  7. apply the result under that lock, or drop it as a late result
 *
 * Render polls spend nothing and stay outside (Budget::meterFreeShotCall).
 */
class PaidCall
{
    public function __construct(
        private readonly Budget $budget,
        private readonly RateLimitHitCounter $rateLimits,
    ) {}

    /**
     * Run one shot step. A shot is live until it is failed.
     *
     * @template TResult
     * @template TApplied
     *
     * @param  string  $provider  ledger provider, also the key 429s are counted under
     * @param  Closure(): TResult  $call  the provider call, plus any upload that needs no lock
     * @param  Closure(Shot, TResult): TApplied  $apply  database writes, on the locked live shot
     * @param  (Closure(Shot): void)|null  $onFailure  runs on the locked live shot after a failure that kept its charge
     */
    public function forShot(
        Shot $shot,
        string $provider,
        string $operation,
        Closure $call,
        Closure $apply,
        ?PaidCallClaim $claim = null,
        ?Closure $onFailure = null,
    ): PaidCallResult {
        $withLocked = fn (Closure $callback): mixed => DB::transaction(
            fn (): mixed => $callback(Shot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail()),
        );

        try {
            return $this->run(
                charge: fn (): bool => $this->budget->chargeShotStep($shot, $provider, $operation),
                refund: fn () => $this->budget->refundShotStep($shot, $provider, $operation),
                withLocked: $withLocked,
                isLive: fn (Shot $locked): bool => $locked->state !== ShotState::Failed,
                provider: $provider,
                call: $call,
                apply: $apply,
                claim: $claim,
                onFailure: $onFailure,
            );
        } finally {
            $shot->refresh();
        }
    }

    /**
     * Run one brief step. A brief is live for the step while it stays in $stage.
     *
     * @template TResult
     * @template TApplied
     *
     * @param  string  $provider  ledger provider, also the key 429s are counted under
     * @param  Closure(): TResult  $call  the provider call, plus any upload that needs no lock
     * @param  Closure(Brief, TResult): TApplied  $apply  database writes, on the locked live brief
     */
    public function forBrief(
        Brief $brief,
        BriefState $stage,
        string $provider,
        string $operation,
        Closure $call,
        Closure $apply,
        ?PaidCallClaim $claim = null,
    ): PaidCallResult {
        $withLocked = fn (Closure $callback): mixed => DB::transaction(
            fn (): mixed => $callback(Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail()),
        );

        try {
            return $this->run(
                charge: fn (): bool => $this->budget->chargeOrDegrade($brief, $provider, $operation),
                refund: fn () => $this->budget->refund($brief, $provider, $operation),
                withLocked: $withLocked,
                isLive: fn (Brief $locked): bool => $locked->state === $stage,
                provider: $provider,
                call: $call,
                apply: $apply,
                claim: $claim,
            );
        } finally {
            $brief->refresh();
        }
    }

    /**
     * @param  Closure(): bool  $charge
     * @param  Closure(): mixed  $refund
     * @param  Closure(Closure(Model): mixed): mixed  $withLocked  run a callback on the locked row
     * @param  Closure(Model): bool  $isLive
     */
    private function run(
        Closure $charge,
        Closure $refund,
        Closure $withLocked,
        Closure $isLive,
        string $provider,
        Closure $call,
        Closure $apply,
        ?PaidCallClaim $claim,
        ?Closure $onFailure = null,
    ): PaidCallResult {
        if (! $charge()) {
            return new PaidCallResult(PaidCallOutcome::ChargeRefused);
        }

        $isClaimed = $claim === null
            || $withLocked(fn (Model $locked): bool => $isLive($locked) && ($claim->take)($locked));

        if (! $isClaimed) {
            $refund();

            return new PaidCallResult(PaidCallOutcome::ClaimLost);
        }

        try {
            $result = $call();
        } catch (ProviderRateLimitedException $exception) {
            // A refused request spent no quota, so the charge and the claim go back.
            $refund();

            if ($claim !== null) {
                $withLocked($claim->release);
            }

            $this->rateLimits->increment($provider);

            throw $exception;
        } catch (Throwable $exception) {
            if ($onFailure !== null) {
                $withLocked(function (Model $locked) use ($isLive, $onFailure): void {
                    if ($isLive($locked)) {
                        $onFailure($locked);
                    }
                });
            }

            throw $exception;
        }

        return $withLocked(function (Model $locked) use ($isLive, $apply, $claim, $result): PaidCallResult {
            if (! $isLive($locked)) {
                if ($claim !== null) {
                    ($claim->release)($locked);
                }

                return new PaidCallResult(PaidCallOutcome::Late);
            }

            return new PaidCallResult(PaidCallOutcome::Applied, $apply($locked, $result));
        });
    }
}
