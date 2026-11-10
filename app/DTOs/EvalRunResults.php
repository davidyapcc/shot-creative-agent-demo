<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\EvalRun;

/**
 * One eval run's id and its stored per-clip results, as judge agreement reads them.
 */
final class EvalRunResults
{
    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function __construct(
        public readonly int|string $runId,
        public readonly array $results,
    ) {}

    public static function fromRun(EvalRun $run): self
    {
        return new self($run->id, array_values($run->results ?? []));
    }
}
