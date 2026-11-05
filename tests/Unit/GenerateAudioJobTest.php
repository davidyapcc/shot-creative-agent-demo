<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AudioProviderInterface;
use App\DTOs\AudioResult;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\GenerateAudioJob;
use App\Jobs\MuxFinalVideoJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GenerateAudioJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_shot_retry_does_not_regenerate_brief_audio(): void
    {
        Storage::fake('s3');
        Queue::fake([MuxFinalVideoJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Audio,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::Done,
            'attempts' => 2,
            'video_path' => 'videos/brief_shot_1.mp4',
        ]);

        $provider = new class implements AudioProviderInterface
        {
            public int $calls = 0;

            public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
            {
                $this->calls++;

                return new AudioResult(
                    voiceover: 'VOICEOVER',
                    soundEffect: 'SFX',
                );
            }
        };

        $job = new GenerateAudioJob($brief->id);
        $job->handle($provider, app(Budget::class));

        $brief->refresh();
        $voiceoverPath = $brief->audio_path;
        $soundEffectPath = $brief->metadata['sound_effect_path'] ?? null;

        $this->assertSame(BriefState::Muxing, $brief->state);
        $this->assertSame('VOICEOVER', Storage::disk('s3')->get($voiceoverPath));
        $this->assertSame('SFX', Storage::disk('s3')->get($soundEffectPath));
        $this->assertSame(1, $provider->calls);
        Queue::assertPushed(MuxFinalVideoJob::class, 1);

        $shot->update(['attempts' => 3]);
        $job->handle($provider, app(Budget::class));

        $brief->refresh();
        $this->assertSame(1, $provider->calls);
        $this->assertSame($voiceoverPath, $brief->audio_path);
        $this->assertSame($soundEffectPath, $brief->metadata['sound_effect_path'] ?? null);
        $this->assertSame('VOICEOVER', Storage::disk('s3')->get($voiceoverPath));
        $this->assertSame('SFX', Storage::disk('s3')->get($soundEffectPath));
        Queue::assertPushed(MuxFinalVideoJob::class, 1);
    }

    public function test_overlapping_audio_job_does_not_generate_twice(): void
    {
        Storage::fake('s3');
        Queue::fake([MuxFinalVideoJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Audio,
        ]);

        $provider = new class($brief->id) implements AudioProviderInterface
        {
            public int $calls = 0;

            public ?string $claimedPath = null;

            public function __construct(private int $briefId) {}

            public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
            {
                $this->calls++;
                $this->claimedPath = Brief::query()->findOrFail($this->briefId)->audio_path;

                if ($this->calls === 1) {
                    (new GenerateAudioJob($this->briefId))->handle($this, app(Budget::class));
                }

                return new AudioResult(
                    voiceover: 'VOICEOVER',
                    soundEffect: 'SFX',
                );
            }
        };

        (new GenerateAudioJob($brief->id))->handle($provider, app(Budget::class));

        $this->assertSame(1, $provider->calls);
        $this->assertNotNull($provider->claimedPath);
        $this->assertSame('VOICEOVER', Storage::disk('s3')->get($brief->refresh()->audio_path));
        Queue::assertPushed(MuxFinalVideoJob::class, 1);
    }

    public function test_failed_generation_is_not_repeated(): void
    {
        Storage::fake('s3');
        Queue::fake([MuxFinalVideoJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Audio,
        ]);

        $provider = new class implements AudioProviderInterface
        {
            public int $calls = 0;

            public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
            {
                $this->calls++;

                throw new RuntimeException('ElevenLabs rejected the sound effect.');
            }
        };

        $job = new GenerateAudioJob($brief->id);

        try {
            $job->handle($provider, app(Budget::class));
            $this->fail('The provider failure should escape the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ElevenLabs rejected the sound effect.', $exception->getMessage());
        }

        $this->assertNotNull($brief->refresh()->audio_path);

        $job->handle($provider, app(Budget::class));

        $this->assertSame(1, $provider->calls);
        Queue::assertNothingPushed();
    }

    public function test_audio_job_is_rate_limited_and_retries_for_two_hours(): void
    {
        $job = new GenerateAudioJob(1);

        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertTrue(collect($middleware)->contains(fn (object $m): bool => $m instanceof RateLimited));
        $this->assertTrue(collect($middleware)->contains(fn (object $m): bool => $m instanceof ThrottlesExceptions));
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_provider_rate_limit_releases_the_job_for_a_later_retry(): void
    {
        $job = (new GenerateAudioJob(1))->withFakeQueueInteractions();

        $this->throttle($job)->handle($job, function (): void {
            throw new ProviderRateLimitedException('ElevenLabs returned 429.');
        });

        $job->assertReleased();
        $job->assertNotFailed();
    }

    public function test_other_provider_errors_fail_the_job_instead_of_retrying(): void
    {
        $job = (new GenerateAudioJob(1))->withFakeQueueInteractions();

        $this->throttle($job)->handle($job, function (): void {
            throw new RuntimeException('ElevenLabs rejected the sound effect.');
        });

        $job->assertFailed();
        $job->assertNotReleased();
    }

    public function test_rate_limited_generation_gives_back_the_claim_so_a_retry_can_generate(): void
    {
        Storage::fake('s3');
        Queue::fake([MuxFinalVideoJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Audio,
        ]);

        $provider = new class implements AudioProviderInterface
        {
            public int $calls = 0;

            public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new ProviderRateLimitedException('ElevenLabs returned 429.');
                }

                return new AudioResult(voiceover: 'VOICEOVER', soundEffect: 'SFX');
            }
        };

        try {
            (new GenerateAudioJob($brief->id))->handle($provider, app(Budget::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $this->assertNull($brief->audio_path);
            $this->assertArrayNotHasKey('sound_effect_path', $brief->metadata ?? []);
        }

        (new GenerateAudioJob($brief->id))->handle($provider, app(Budget::class));

        $this->assertSame(2, $provider->calls);
        $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
        Queue::assertPushed(MuxFinalVideoJob::class, 1);
    }

    private function throttle(GenerateAudioJob $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $m): bool => $m instanceof ThrottlesExceptions);
    }
}
