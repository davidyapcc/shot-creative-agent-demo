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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one owner of a paid call. Every shot step and brief step runs through
 * here, in this order:
 *
 *  1. charge first; a refused charge never reaches the provider
 *  2. take the claim, where the step has one; a lost claim refunds the charge
 *  3. call the provider
 *  4. on a 429: refund, release the claim, count the hit, rethrow
 *  5. on any other failure: keep the charge and the claim, run onFailure, rethrow
 *  6. re-check under a row lock that the shot or brief is still live
 *  7. apply the result under that lock, or drop it as a late result
 *
 * Render polls spend nothing and stay outside (Budget::meterFreeShotCall).
 * Both entry points refresh the model they were given before returning, so
 * the caller sees what apply wrote.
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
     * @param  (Closure(Shot): void)|null  $onFailure  runs on the locked shot after a failure that kept its charge
     * @return PaidCallResult<TApplied>
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
        $target = new PaidCallTarget(
            charge: fn (): bool => $this->budget->chargeShotStep($shot, $provider, $operation),
            refund: fn () => $this->budget->refundShotStep($shot, $provider, $operation),
            lock: fn (): Shot => Shot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail(),
            isLive: fn (Shot $locked): bool => $locked->state !== ShotState::Failed,
        );

        return $this->runAndRefresh($shot, $target, $provider, $call, $apply, $claim, $onFailure);
    }

    /**
     * Run one brief step. A brief is live for the step while it stays in $stage.
     * Budget::refund reverses only the latest ledger entry, which is safe here
     * because brief steps never run in parallel with each other.
     *
     * @template TResult
     * @template TApplied
     *
     * @param  string  $provider  ledger provider, also the key 429s are counted under
     * @param  Closure(): TResult  $call  the provider call, plus any upload that needs no lock
     * @param  Closure(Brief, TResult): TApplied  $apply  database writes, on the locked live brief
     * @return PaidCallResult<TApplied>
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
        $target = new PaidCallTarget(
            charge: fn (): bool => $this->budget->chargeOrDegrade($brief, $provider, $operation),
            refund: fn () => $this->budget->refund($brief, $provider, $operation),
            lock: fn (): Brief => Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail(),
            isLive: fn (Brief $locked): bool => $locked->state === $stage,
        );

        return $this->runAndRefresh($brief, $target, $provider, $call, $apply, $claim);
    }

    private function runAndRefresh(
        Model $model,
        PaidCallTarget $target,
        string $provider,
        Closure $call,
        Closure $apply,
        ?PaidCallClaim $claim,
        ?Closure $onFailure = null,
    ): PaidCallResult {
        try {
            if (! ($target->charge)()) {
                return new PaidCallResult(PaidCallOutcome::ChargeRefused);
            }

            if (! $this->takeClaim($target, $claim)) {
                ($target->refund)();

                return new PaidCallResult(PaidCallOutcome::ClaimLost);
            }

            $result = $this->callProvider($target, $provider, $call, $claim, $onFailure);

            return $this->applyIfLive($target, $apply, $claim, $result);
        } finally {
            $model->refresh();
        }
    }

    private function takeClaim(PaidCallTarget $target, ?PaidCallClaim $claim): bool
    {
        if ($claim === null) {
            return true;
        }

        return $target->withLocked(
            fn (Model $locked): bool => ($target->isLive)($locked) && ($claim->take)($locked),
        );
    }

    private function callProvider(
        PaidCallTarget $target,
        string $provider,
        Closure $call,
        ?PaidCallClaim $claim,
        ?Closure $onFailure,
    ): mixed {
        try {
            return $call();
        } catch (ProviderRateLimitedException $exception) {
            // A refused request spent no quota, so the charge and the claim go back.
            $this->cleanUpAfter($exception, function () use ($target, $claim, $provider): void {
                ($target->refund)();

                if ($claim !== null) {
                    $target->withLocked($claim->release);
                }

                $this->rateLimits->increment($provider);
            });

            throw $exception;
        } catch (Throwable $exception) {
            // Any other failure keeps its charge; a stopped shot still counts the generation.
            if ($onFailure !== null) {
                $this->cleanUpAfter($exception, fn () => $target->withLocked($onFailure));
            }

            throw $exception;
        }
    }

    private function applyIfLive(PaidCallTarget $target, Closure $apply, ?PaidCallClaim $claim, mixed $result): PaidCallResult
    {
        return $target->withLocked(function (Model $locked) use ($target, $apply, $claim, $result): PaidCallResult {
            if (($target->isLive)($locked)) {
                return new PaidCallResult(PaidCallOutcome::Applied, $apply($locked, $result));
            }

            if ($claim !== null) {
                ($claim->release)($locked);
            }

            return new PaidCallResult(PaidCallOutcome::Late);
        });
    }

    /**
     * Run cleanup after a failed provider call. A cleanup error is logged, so
     * the provider's own exception is the one the queue sees.
     */
    private function cleanUpAfter(Throwable $original, Closure $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $cleanupError) {
            Log::error('Paid-call cleanup failed after a provider error.', [
                'provider_error' => $original->getMessage(),
                'cleanup_error' => $cleanupError->getMessage(),
            ]);
        }
    }
}
