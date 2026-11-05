<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PlanBriefJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;

    public function __construct(
        public readonly int $briefId,
    ) {}

    public function handle(AgentInterface $agent, Budget $budget): void
    {
        $brief = Brief::findOrFail($this->briefId);

        $brief->transitionTo(BriefState::Planning);

        if (! $budget->chargeOrDegrade($brief, 'agent', 'generateStyleAnchor')) {
            return;
        }

        $styleAnchor = $agent->generateStyleAnchor($brief->prompt);
        $brief->update([
            'style_anchor' => $styleAnchor,
        ]);

        if (! $budget->chargeOrDegrade($brief, 'agent', 'planShots')) {
            return;
        }

        $plannedShots = $agent->planShots($brief->prompt);

        foreach ($plannedShots as $shotData) {
            $shot = Shot::create([
                'brief_id' => $brief->id,
                'sequence' => $shotData['sequence'],
                'prompt' => $shotData['prompt'],
                'state' => ShotState::Pending,
            ]);

            ShotUpdated::dispatch($shot);
        }

        $brief->transitionTo(BriefState::Keyframes);

        ProcessKeyframesJob::dispatch($brief->id);
    }
}
