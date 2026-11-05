<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Events\BriefStateChanged;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brief_state_changed_event_implements_broadcast_now_and_broadcasts_on_per_brief_channel(): void
    {
        $event = new BriefStateChanged(
            briefId: 42,
            from: BriefState::Pending,
            to: BriefState::Planning,
        );

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('briefs.42', $channels[0]->name);

        $this->assertSame('BriefStateChanged', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['briefId']);
        $this->assertSame('pending', $payload['from']);
        $this->assertSame('planning', $payload['to']);
    }

    public function test_video_progress_updated_event_broadcasts_on_per_brief_channel(): void
    {
        $event = new VideoProgressUpdated(
            briefId: 42,
            shotId: 101,
            progress: 40,
        );

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('briefs.42', $channels[0]->name);
        $this->assertSame('VideoProgressUpdated', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['briefId']);
        $this->assertSame(101, $payload['shotId']);
        $this->assertSame(40, $payload['progress']);
    }

    public function test_shot_updated_event_broadcasts_shot_and_latest_judgement_on_per_brief_channel(): void
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Rendering,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Neon alley reflection',
            'state' => ShotState::JudgingVideo,
            'keyframe_path' => 'keyframes/shot_1.png',
            'video_path' => 'videos/shot_1.mp4',
            'attempts' => 1,
        ]);

        $judgement = Judgement::create([
            'shot_id' => $shot->id,
            'stage' => 'video',
            'scores' => ['prompt_adherence' => 4, 'anatomy' => 2],
            'hard_fail_tags' => ['anatomy'],
            'reason' => 'Anatomy deformation detected',
            'verdict' => 'fail',
        ]);

        $event = new ShotUpdated($shot);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame("briefs.{$brief->id}", $channels[0]->name);
        $this->assertSame('ShotUpdated', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($brief->id, $payload['briefId']);
        $this->assertSame($shot->id, $payload['shot']['id']);
        $this->assertSame(1, $payload['shot']['sequence']);
        $this->assertSame('judging_video', $payload['shot']['state']);
        $this->assertSame('keyframes/shot_1.png', $payload['shot']['keyframe_path']);
        $this->assertSame('/media/keyframes/shot_1.png', $payload['shot']['keyframe_url']);
        $this->assertSame('videos/shot_1.mp4', $payload['shot']['video_path']);
        $this->assertSame('/media/videos/shot_1.mp4', $payload['shot']['video_url']);
        $this->assertSame(1, $payload['shot']['attempts']);
        $this->assertSame('fail', $payload['shot']['latest_judgement']['verdict']);
        $this->assertSame(['anatomy'], $payload['shot']['latest_judgement']['hard_fail_tags']);
    }

    public function test_judge_verdict_recorded_event_broadcasts_failure_tags_on_per_brief_channel(): void
    {
        $event = new JudgeVerdictRecorded(
            briefId: 42,
            shotId: 101,
            stage: JudgementStage::Video,
            verdict: JudgementVerdict::Fail,
            hardFailTags: ['anatomy'],
            reason: 'Hand distorted',
        );

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('briefs.42', $channels[0]->name);
        $this->assertSame('JudgeVerdictRecorded', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['briefId']);
        $this->assertSame(101, $payload['shotId']);
        $this->assertSame('video', $payload['stage']);
        $this->assertSame('fail', $payload['verdict']);
        $this->assertArrayNotHasKey('tag', $payload);
        $this->assertSame(['anatomy'], $payload['hardFailTags']);
        $this->assertSame('Hand distorted', $payload['reason']);
    }
}
