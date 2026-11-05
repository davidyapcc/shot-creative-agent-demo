<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Services\Agent\MockAgent;
use InvalidArgumentException;
use Tests\TestCase;

class MockAgentTest extends TestCase
{
    public function test_mock_agent_plans_shots_deterministically(): void
    {
        $agent = new MockAgent;
        $prompt = '15s product teaser, rainy Tokyo, neon';

        $firstPlan = $agent->planShots($prompt);
        $secondPlan = $agent->planShots($prompt);

        $this->assertCount(3, $firstPlan);
        $this->assertSame($firstPlan, $secondPlan);
        $this->assertSame(1, $firstPlan[0]['sequence']);
        $this->assertSame(2, $firstPlan[1]['sequence']);
        $this->assertSame(3, $firstPlan[2]['sequence']);
        $this->assertStringContainsString('Tokyo', $firstPlan[0]['prompt']);
    }

    public function test_mock_agent_generates_style_anchor(): void
    {
        $agent = new MockAgent;
        $prompt = '15s product teaser, rainy Tokyo, neon';

        $styleAnchor = $agent->generateStyleAnchor($prompt);

        $this->assertNotEmpty($styleAnchor);
        $this->assertStringContainsString('Tokyo', $styleAnchor);
    }

    public function test_mock_rewrite_keeps_the_scene_and_does_not_repeat_a_failed_fix(): void
    {
        $agent = new MockAgent;
        $scene = 'Shot 1: Wide establishing shot setting the scene. rainy Tokyo';

        $first = $agent->rewritePrompt([
            'brief' => 'rainy Tokyo',
            'original_prompt' => $scene,
            'failed_scores' => ['anatomy' => 2],
            'reason' => 'Left hand has six fingers.',
            'previous_attempts' => [],
        ]);

        $this->assertStringStartsWith($scene, $first);
        $this->assertStringContainsString('Simplify only this failure', $first);
        $this->assertStringContainsString('Left hand has six fingers.', $first);

        $second = $agent->rewritePrompt([
            'brief' => 'rainy Tokyo',
            'original_prompt' => $first,
            'failed_scores' => ['anatomy' => 2],
            'reason' => 'Left hand has six fingers.',
            'previous_attempts' => [
                ['prompt' => $scene, 'reason' => 'Left hand has six fingers.'],
            ],
        ]);

        $this->assertStringStartsWith($scene, $second);
        $this->assertStringContainsString('different simplification', $second);
        $this->assertSame(1, substr_count($second, 'Simplify only this failure'));
    }

    public function test_unsupported_agent_provider_throws_invalid_argument_exception(): void
    {
        config(['creative.agent.provider' => 'invalid_provider']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported agent provider [invalid_provider].');

        app(AgentInterface::class);
    }
}
