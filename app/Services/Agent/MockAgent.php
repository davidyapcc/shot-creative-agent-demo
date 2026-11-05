<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Contracts\AgentInterface;
use App\DTOs\ShotPlan;

class MockAgent implements AgentInterface
{
    public const UNKNOWN_REASON = 'the failing element';

    /**
     * Plan 3 shots and a style anchor deterministically from the prompt.
     */
    public function planShots(string $prompt): ShotPlan
    {
        return new ShotPlan("Consistent cinematic style, high dynamic range, matching color palette for: {$prompt}", [
            [
                'sequence' => 1,
                'prompt' => "Shot 1: Wide establishing shot setting the scene. {$prompt}",
            ],
            [
                'sequence' => 2,
                'prompt' => "Shot 2: Medium shot focusing on the central subject and atmosphere. {$prompt}",
            ],
            [
                'sequence' => 3,
                'prompt' => "Shot 3: Dynamic close-up highlighting key details and resolution. {$prompt}",
            ],
        ]);
    }

    public function rewritePrompt(array $context): string
    {
        $scene = $context['original_prompt'];
        $reason = $context['reason'] ?? self::UNKNOWN_REASON;

        return $scene.' '.RewriteInstructions::for($reason, $scene, $context['previous_attempts']);
    }
}
