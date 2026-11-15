<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The outcome of a paid call, with whatever its apply step returned.
 *
 * @template TApplied
 */
final readonly class PaidCallResult
{
    /**
     * @param  TApplied|null  $value  set only when the outcome is Applied
     */
    public function __construct(
        public PaidCallOutcome $outcome,
        public mixed $value = null,
    ) {}

    public function wasApplied(): bool
    {
        return $this->outcome === PaidCallOutcome::Applied;
    }
}
