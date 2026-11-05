<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Exceptions\BudgetExceededException;
use App\Jobs\PlanBriefJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_ledger_meters_each_call_including_a_free_one(): void
    {
        config(['creative.budget_cap' => 2]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
        ]);

        $budget = app(Budget::class);
        $budget->charge($brief, 'image', 'generateImage');
        $budget->charge($brief, 'judge', 'judgeImage');

        $brief->refresh();
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame([
            ['provider' => 'image', 'operation' => 'generateImage', 'cost' => 1],
            ['provider' => 'judge', 'operation' => 'judgeImage', 'cost' => 1],
        ], $brief->metadata['budget_ledger']);

        try {
            $budget->charge($brief, 'video', 'submit');
            $this->fail('A call at the cap should be refused.');
        } catch (BudgetExceededException) {
            $brief->refresh();
            $this->assertSame(2, $brief->budget_used);
            $this->assertCount(2, $brief->metadata['budget_ledger']);
        }
    }

    public function test_refund_logs_a_warning_when_another_charge_landed_in_between(): void
    {
        // Arrange
        Log::spy();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Audio,
        ]);

        $budget = app(Budget::class);
        $budget->charge($brief, 'audio', 'generateVoiceover');
        $budget->charge($brief, 'video', 'poll');

        // Act
        $budget->refund($brief, 'audio', 'generateVoiceover');

        // Assert
        $this->assertSame(2, $brief->refresh()->budget_used);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refund')
              && $context['brief_id'] === $brief->id
              && $context['operation'] === 'generateVoiceover');
    }

    public function test_cap_during_planning_stops_the_next_agent_call_and_marks_the_brief_degraded(): void
    {
        config(['creative.budget_cap' => 1]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Pending,
        ]);

        $agent = new CountingAgent;
        (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class));

        $this->assertSame(1, $agent->styleCalls);
        $this->assertSame(0, $agent->planCalls);
        $brief->refresh();
        $this->assertSame(BriefState::Degraded, $brief->state);
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame('agent', $brief->metadata['budget_ledger'][0]['provider']);
        $this->assertCount(0, $brief->shots);
    }

    public function test_cap_stops_further_renders_and_muxes_the_clip_that_passed(): void
    {
        Storage::fake('s3');
        config(['creative.budget_cap' => 0]);

        $clip = $this->replayClip();
        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Rendering,
        ]);

        $passed = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::Done,
            'video_path' => 'videos/shot_1.mp4',
        ]);
        Storage::disk('s3')->put($passed->video_path, $clip);

        $pending = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: neon sign',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_2.png',
        ]);

        $video = $this->createMock(VideoProviderInterface::class);
        $video->expects($this->never())->method('submit');

        (new RenderShotsJob($brief->id))->handle($video);

        $brief->refresh();
        $this->assertSame(BriefState::Degraded, $brief->state);
        $this->assertSame(0, $brief->budget_used);
        $this->assertNotNull($brief->final_video_path);
        $this->assertNotSame('', Storage::disk('s3')->get($brief->final_video_path));
        $this->assertSame(ShotState::Done, $passed->refresh()->state);
        $this->assertSame(ShotState::Failed, $pending->refresh()->state);
    }

    private function replayClip(): string
    {
        $provider = new ReplayVideoProvider(pendingPolls: 0);
        $renderId = $provider->submit('A neon alley', 'keyframes/shot.png');

        return (string) $provider->poll($renderId)->videoContent;
    }
}

class CountingAgent implements AgentInterface
{
    public int $styleCalls = 0;

    public int $planCalls = 0;

    public function planShots(string $prompt): array
    {
        $this->planCalls++;

        return [];
    }

    public function generateStyleAnchor(string $prompt): string
    {
        $this->styleCalls++;

        return 'cinematic neon rain';
    }

    public function rewritePrompt(array $context): string
    {
        return $context['original_prompt'];
    }
}
