<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How a paid call ended.
 */
enum PaidCallOutcome
{
    /** The budget refused the charge, so the provider was never called. */
    case ChargeRefused;

    /** Another run holds the claim, so the charge was refunded and the provider was never called. */
    case ClaimLost;

    /** The provider answered after the shot or brief stopped being live; the late result was dropped. */
    case Late;

    /** The result was applied. */
    case Applied;
}
