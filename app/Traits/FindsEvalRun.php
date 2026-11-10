<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\EvalRun;

/**
 * Looks up an eval run from a console argument and reports a missing one.
 * For Artisan commands only: it writes the error through `Command::error`.
 */
trait FindsEvalRun
{
    private function findRun(mixed $id): ?EvalRun
    {
        $run = EvalRun::query()->find($id);

        if ($run === null) {
            $this->error("Eval run {$id} was not found.");
        }

        return $run;
    }
}
