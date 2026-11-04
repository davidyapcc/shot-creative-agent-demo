<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by a provider when its API refuses a request with a rate limit
 * (HTTP 429). Jobs treat it as retryable; any other provider error fails the job.
 */
class ProviderRateLimitedException extends RuntimeException
{
}
