<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_brief_can_transition_through_valid_states(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $brief->transitionTo(BriefState::Planning);
        $this->assertSame(BriefState::Planning, $brief->state);

        $brief->transitionTo(BriefState::Keyframes);
        $this->assertSame(BriefState::Keyframes, $brief->state);

        $brief->transitionTo(BriefState::Rendering);
        $this->assertSame(BriefState::Rendering, $brief->state);

        $brief->transitionTo(BriefState::Audio);
        $this->assertSame(BriefState::Audio, $brief->state);

        $brief->transitionTo(BriefState::Muxing);
        $this->assertSame(BriefState::Muxing, $brief->state);

        $brief->transitionTo(BriefState::Done);
        $this->assertSame(BriefState::Done, $brief->state);
    }

    public function test_brief_rejects_illegal_transition(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $this->expectException(InvalidStateTransitionException::class);
        $brief->transitionTo(BriefState::Done);
    }

    public function test_brief_terminal_state_cannot_transition(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $brief->transitionTo(BriefState::Failed);

        $this->expectException(InvalidStateTransitionException::class);
        $brief->transitionTo(BriefState::Planning);
    }

    public function test_brief_rejects_transition_with_mismatched_state_enum(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage('Brief expects a BriefState, got ShotState.');
        $brief->transitionTo(ShotState::Pending);
    }

    public function test_shot_can_transition_through_valid_states(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Wide shot of cyberpunk neon alley with reflection on puddles',
            'state' => ShotState::Pending,
        ]);

        $this->assertSame(ShotState::Pending, $shot->state);

        $shot->transitionTo(ShotState::GeneratingImage);
        $this->assertSame(ShotState::GeneratingImage, $shot->state);

        $shot->transitionTo(ShotState::JudgingImage);
        $this->assertSame(ShotState::JudgingImage, $shot->state);

        $shot->transitionTo(ShotState::GeneratingVideo);
        $this->assertSame(ShotState::GeneratingVideo, $shot->state);

        $shot->transitionTo(ShotState::JudgingVideo);
        $this->assertSame(ShotState::JudgingVideo, $shot->state);

        $shot->transitionTo(ShotState::Done);
        $this->assertSame(ShotState::Done, $shot->state);
    }

    public function test_shot_rejects_illegal_transition_from_pending_to_done(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Wide shot of cyberpunk neon alley',
            'state' => ShotState::Pending,
        ]);

        $this->expectException(InvalidStateTransitionException::class);
        $shot->transitionTo(ShotState::Done);
    }

    public function test_shot_rejects_illegal_transition_from_generating_image_to_done(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Wide shot of cyberpunk neon alley',
            'state' => ShotState::Pending,
        ]);

        $shot->transitionTo(ShotState::GeneratingImage);

        $this->expectException(InvalidStateTransitionException::class);
        $shot->transitionTo(ShotState::Done);
    }

    public function test_shot_terminal_state_cannot_transition(): void
    {
        $brief = Brief::create([
            'prompt' => 'A futuristic neon city in the rain',
            'state' => BriefState::Pending,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Wide shot of cyberpunk neon alley',
            'state' => ShotState::Pending,
        ]);

        $shot->transitionTo(ShotState::Failed);

        $this->expectException(InvalidStateTransitionException::class);
        $shot->transitionTo(ShotState::GeneratingImage);
    }
}
