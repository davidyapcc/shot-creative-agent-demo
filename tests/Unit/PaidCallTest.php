<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\PaidCall;
use App\Services\PaidCallClaim;
use App\Services\PaidCallOutcome;
use App\Services\RateLimitHitCounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class PaidCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_a_shot_step_charges_once_and_applies_its_result_to_a_live_shot(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();

        // Act
        $result = app(PaidCall::class)->forShot(
            $shot,
            'image',
            'generateImage',
            call: fn (): string => 'keyframes/new.png',
            apply: function (Shot $locked, string $path): string {
                $locked->update(['keyframe_path' => $path]);

                return 'applied';
            },
        );

        // Assert
        $this->assertSame(PaidCallOutcome::Applied, $result->outcome);
        $this->assertSame('applied', $result->value);
        $this->assertSame('keyframes/new.png', $shot->keyframe_path);
        $this->assertSame(1, $brief->refresh()->budget_used);
        $this->assertSame(
            [['provider' => 'image', 'operation' => 'generateImage', 'cost' => 1, 'shot_id' => $shot->id]],
            $brief->metadata['budget_ledger'],
        );
    }

    public function test_a_refused_charge_never_calls_the_provider(): void
    {
        // Arrange
        config(['creative.budget_cap' => 0]);
        [$brief, $shot] = $this->briefWithShot();
        $calls = 0;

        // Act
        $result = app(PaidCall::class)->forShot(
            $shot,
            'image',
            'generateImage',
            call: function () use (&$calls): string {
                $calls++;

                return 'unused';
            },
            apply: fn (Shot $locked, string $path): bool => $locked->update(['keyframe_path' => $path]),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::ChargeRefused, $result->outcome);
        $this->assertFalse($result->wasApplied());
        $this->assertSame(0, $calls);
        $this->assertNull($shot->refresh()->keyframe_path);
    }

    public function test_a_late_shot_result_is_dropped_and_keeps_its_charge(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();

        // Act
        $result = app(PaidCall::class)->forShot(
            $shot,
            'image',
            'generateImage',
            call: function () use ($shot): string {
                Shot::query()->whereKey($shot->id)->update(['state' => ShotState::Failed->value]);

                return 'keyframes/late.png';
            },
            apply: fn (Shot $locked, string $path): bool => $locked->update(['keyframe_path' => $path]),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::Late, $result->outcome);
        $this->assertNull($shot->refresh()->keyframe_path);
        $this->assertSame(ShotState::Failed, $shot->state);
        $this->assertSame(1, $brief->refresh()->budget_used);
    }

    public function test_a_rate_limited_shot_step_refunds_releases_its_claim_counts_the_hit_and_rethrows(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $applied = false;

        // Act
        try {
            app(PaidCall::class)->forShot(
                $shot,
                'agent',
                'rewritePrompt',
                call: fn () => throw new ProviderRateLimitedException('HTTP 429'),
                apply: function () use (&$applied): void {
                    $applied = true;
                },
                claim: $this->metadataClaim('rewrite_claimed'),
            );
            $this->fail('The 429 was swallowed.');
        } catch (ProviderRateLimitedException) {
            // Assert
            $this->assertFalse($applied);
            $this->assertSame(0, $brief->refresh()->budget_used);
            $this->assertSame([], $brief->metadata['budget_ledger']);
            $this->assertArrayNotHasKey('rewrite_claimed', $shot->refresh()->metadata ?? []);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }
    }

    public function test_any_other_failure_keeps_the_charge_and_the_claim_and_reports_to_the_step(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();

        // Act
        try {
            app(PaidCall::class)->forShot(
                $shot,
                'image',
                'generateImage',
                call: fn () => throw new RuntimeException('HTTP 500'),
                apply: fn () => $this->fail('A failed call has no result to apply.'),
                claim: $this->metadataClaim('claimed'),
                onFailure: fn (Shot $locked) => $locked->recordStepAttempt('image'),
            );
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            // Assert
            $this->assertSame('HTTP 500', $exception->getMessage());
            $this->assertSame(1, $brief->refresh()->budget_used);
            $this->assertTrue($shot->refresh()->metadata['claimed']);
            $this->assertSame(1, $shot->attempts);
            $this->assertSame(0, app(RateLimitHitCounter::class)->total());
        }
    }

    public function test_a_failed_generation_counts_even_after_the_shot_was_stopped_mid_call(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $caught = null;

        // Act
        try {
            app(PaidCall::class)->forShot(
                $shot,
                'image',
                'generateImage',
                call: function () use ($shot): never {
                    Shot::query()->whereKey($shot->id)->update(['state' => ShotState::Failed->value]);

                    throw new RuntimeException('HTTP 500');
                },
                apply: fn () => $this->fail('A failed call has no result to apply.'),
                onFailure: fn (Shot $locked) => $locked->recordStepAttempt('image'),
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        // Assert
        $this->assertSame('HTTP 500', $caught?->getMessage());
        $shot->refresh();
        $this->assertSame(ShotState::Failed, $shot->state);
        $this->assertSame(1, $shot->attempts);
        $this->assertSame(1, $brief->refresh()->budget_used);
    }

    public function test_a_cleanup_error_after_a_429_is_logged_and_the_429_still_surfaces(): void
    {
        // Arrange
        Log::spy();
        [, $shot] = $this->briefWithShot();
        $caught = null;
        $breakingClaim = new PaidCallClaim(
            take: fn (): bool => true,
            release: fn () => throw new RuntimeException('Lost the database connection.'),
        );

        // Act
        try {
            app(PaidCall::class)->forShot(
                $shot,
                'agent',
                'rewritePrompt',
                call: fn () => throw new ProviderRateLimitedException('HTTP 429'),
                apply: fn () => $this->fail('A refused call has no result to apply.'),
                claim: $breakingClaim,
            );
        } catch (ProviderRateLimitedException $exception) {
            $caught = $exception;
        }

        // Assert
        $this->assertSame('HTTP 429', $caught?->getMessage());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_lost_claim_refunds_the_charge_and_never_calls_the_provider(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $shot->update(['metadata' => ['claimed' => true]]);

        // Act
        $result = app(PaidCall::class)->forShot(
            $shot,
            'agent',
            'rewritePrompt',
            call: fn () => $this->fail('The provider was called without the claim.'),
            apply: fn () => $this->fail('Nothing to apply.'),
            claim: $this->metadataClaim('claimed'),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::ClaimLost, $result->outcome);
        $this->assertSame(0, $brief->refresh()->budget_used);
        $this->assertTrue($shot->refresh()->metadata['claimed']);
    }

    public function test_a_late_result_releases_its_claim(): void
    {
        // Arrange
        [, $shot] = $this->briefWithShot();

        // Act
        $result = app(PaidCall::class)->forShot(
            $shot,
            'agent',
            'rewritePrompt',
            call: function () use ($shot): string {
                Shot::query()->whereKey($shot->id)->update(['state' => ShotState::Failed->value]);

                return 'late rewrite';
            },
            apply: fn () => $this->fail('A late result must not be applied.'),
            claim: $this->metadataClaim('claimed'),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::Late, $result->outcome);
        $this->assertArrayNotHasKey('claimed', $shot->refresh()->metadata ?? []);
    }

    public function test_a_brief_step_applies_its_result_while_the_brief_stays_in_the_steps_stage(): void
    {
        // Arrange
        [$brief] = $this->briefWithShot(BriefState::Audio);

        // Act
        $result = app(PaidCall::class)->forBrief(
            $brief,
            BriefState::Audio,
            'audio',
            'generate',
            call: fn (): string => 'audio/voiceover.wav',
            apply: fn (Brief $locked, string $path): bool => $locked->update(['audio_path' => $path]),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::Applied, $result->outcome);
        $this->assertSame('audio/voiceover.wav', $brief->audio_path);
        $this->assertSame(1, $brief->budget_used);
    }

    public function test_a_brief_result_is_late_once_the_brief_has_left_the_steps_stage(): void
    {
        // Arrange
        [$brief] = $this->briefWithShot(BriefState::Audio);

        // Act
        $result = app(PaidCall::class)->forBrief(
            $brief,
            BriefState::Audio,
            'audio',
            'generate',
            call: function () use ($brief): string {
                Brief::query()->whereKey($brief->id)->update(['state' => BriefState::Failed->value]);

                return 'audio/late.wav';
            },
            apply: fn () => $this->fail('A late result must not be applied.'),
            claim: $this->metadataClaim('claimed'),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::Late, $result->outcome);
        $this->assertSame(BriefState::Failed, $brief->state);
        $this->assertNull($brief->audio_path);
        $this->assertArrayNotHasKey('claimed', $brief->metadata);
        $this->assertSame(1, $brief->budget_used);
    }

    public function test_a_brief_step_with_a_lost_claim_is_refunded_without_a_call(): void
    {
        // Arrange
        [$brief] = $this->briefWithShot(BriefState::Planning);
        $brief->update(['metadata' => ['claimed' => true]]);

        // Act
        $result = app(PaidCall::class)->forBrief(
            $brief,
            BriefState::Planning,
            'agent',
            'planShots',
            call: fn () => $this->fail('The provider was called without the claim.'),
            apply: fn () => $this->fail('Nothing to apply.'),
            claim: $this->metadataClaim('claimed'),
        );

        // Assert
        $this->assertSame(PaidCallOutcome::ClaimLost, $result->outcome);
        $this->assertSame(0, $brief->budget_used);
        $this->assertTrue($brief->metadata['claimed']);
    }

    public function test_a_rate_limited_brief_step_refunds_releases_its_claim_and_counts_the_hit(): void
    {
        // Arrange
        [$brief] = $this->briefWithShot(BriefState::Planning);

        // Act
        try {
            app(PaidCall::class)->forBrief(
                $brief,
                BriefState::Planning,
                'agent',
                'planShots',
                call: fn () => throw new ProviderRateLimitedException('HTTP 429'),
                apply: fn () => $this->fail('A refused call has no result to apply.'),
                claim: $this->metadataClaim('claimed'),
            );
            $this->fail('The 429 was swallowed.');
        } catch (ProviderRateLimitedException) {
            // Assert
            $this->assertSame(0, $brief->budget_used);
            $this->assertArrayNotHasKey('claimed', $brief->metadata);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }
    }

    /**
     * A claim kept as a flag in the row's metadata.
     */
    private function metadataClaim(string $key): PaidCallClaim
    {
        return new PaidCallClaim(
            take: function (Model $locked) use ($key): bool {
                if (($locked->metadata[$key] ?? false) === true) {
                    return false;
                }

                $locked->update(['metadata' => [...$locked->metadata ?? [], $key => true]]);

                return true;
            },
            release: function (Model $locked) use ($key): void {
                $locked->update(['metadata' => array_diff_key($locked->metadata ?? [], [$key => true])]);
            },
        );
    }

    /**
     * @return array{0: Brief, 1: Shot}
     */
    private function briefWithShot(BriefState $state = BriefState::Keyframes): array
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => $state,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1 prompt',
            'state' => ShotState::Pending,
        ]);

        return [$brief, $shot];
    }
}
