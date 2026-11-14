<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * How one step marks its one-shot work as claimed. PaidCall runs both closures
 * on the shot or brief row it has locked; the step only reads and writes its
 * own marker.
 */
final readonly class PaidCallClaim
{
    /**
     * @param  Closure(Model): bool  $take  set the marker; false when another run already holds it
     * @param  Closure(Model): void  $release  clear the marker
     */
    public function __construct(
        public Closure $take,
        public Closure $release,
    ) {}
}
