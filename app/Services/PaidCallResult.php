<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The outcome of a paid call, with whatever its apply step returned.
 */
final readonly class PaidCallResult
{
    public function __construct(
        public PaidCallOutcome $outcome,
        public mixed $value = null,
    ) {}

    public function wasApplied(): bool
    {
        return $this->outcome === PaidCallOutcome::Applied;
    }
}
