<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brief;
use Illuminate\Bus\Batch;
use Throwable;

class FailBriefOnBatchFailureCallback
{
    public function __construct(
        public readonly int $briefId,
    ) {}

    public function __invoke(Batch $batch, ?Throwable $exception = null): void
    {
        $brief = Brief::find($this->briefId);

        $brief?->failWithInFlightShots();
    }
}
