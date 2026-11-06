<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\EvalRun;
use App\Services\EvalBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvalBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_cap_stops_before_another_charge_is_recorded(): void
    {
        $run = EvalRun::query()->create([
            'judge_driver' => 'replay',
            'dataset' => 'golden',
        ]);

        $budget = new EvalBudget;

        $this->assertTrue($budget->charge($run, 2, 'replay', 'judgeVideo', 'steady-street'));
        $this->assertTrue($budget->charge($run, 2, 'replay', 'judgeVideo', 'steady-hands'));
        $this->assertFalse($budget->charge($run, 2, 'replay', 'judgeVideo', 'steady-sign'));

        $run->refresh();

        $this->assertSame(['steady-street', 'steady-hands'], array_column($run->ledger, 'clip_id'));
    }

    public function test_refund_removes_that_clips_charge(): void
    {
        $run = EvalRun::query()->create([
            'judge_driver' => 'replay',
            'dataset' => 'golden',
        ]);

        $budget = new EvalBudget;
        $budget->charge($run, 3, 'replay', 'judgeVideo', 'steady-street');
        $budget->charge($run, 3, 'replay', 'judgeVideo', 'flicker-neon');

        $budget->refund($run, 'replay', 'judgeVideo', 'flicker-neon');

        $run->refresh();

        $this->assertSame(['steady-street'], array_column($run->ledger, 'clip_id'));
        $this->assertSame(1, $budget->spent($run));
    }
}
