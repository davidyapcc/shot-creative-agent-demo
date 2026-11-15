<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The row a paid call is made for (a shot or a brief) and how to charge,
 * refund and lock it. PaidCall builds one per call; steps never see it.
 */
final readonly class PaidCallTarget
{
    /**
     * @param  Closure(): bool  $charge
     * @param  Closure(): void  $refund
     * @param  Closure(): Model  $lock  re-read the row with a lock; call only inside a transaction
     * @param  Closure(Model): bool  $isLive
     */
    public function __construct(
        public Closure $charge,
        public Closure $refund,
        private Closure $lock,
        public Closure $isLive,
    ) {}

    /**
     * Run $callback on the locked row, in one transaction.
     *
     * @template T
     *
     * @param  Closure(Model): T  $callback
     * @return T
     */
    public function withLocked(Closure $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback(($this->lock)()));
    }
}
