<?php

declare(strict_types=1);

namespace App\Enums;

enum EvalRunStatus: string
{
    /** Every golden clip was judged without an error. */
    case Complete = 'complete';

    /** The budget stopped the run early, or at least one clip errored. */
    case Partial = 'partial';
}
