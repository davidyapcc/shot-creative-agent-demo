<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AudioProviderInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\AudioResult;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\VideoRenderStatus;
use App\Services\Providers\FfmpegMotionVideoProvider;
use App\Services\Providers\ReplayAudioProvider;
use App\Services\Providers\ReplayImageProvider;
use App\Services\Providers\ReplayJudge;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
  public function test_replay_image_provider_generates_image_content(): void
  {
    $provider = new ReplayImageProvider();

    $this->assertInstanceOf(ImageProviderInterface::class, $provider);

    $content = $provider->generateImage('A neon lit rainy street', 'cinematic cyber');

    $this->assertNotEmpty($content);
    $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $content);
  }

  public function test_replay_video_provider_submits_and_polls(): void
  {
    $provider = new ReplayVideoProvider(pendingPolls: 1);

    $this->assertInstanceOf(VideoProviderInterface::class, $provider);

    $renderId = $provider->submit('A neon lit rainy street', 'keyframes/shot_1.png');

    $this->assertNotEmpty($renderId);

    // First poll should be pending
    $firstResult = $provider->poll($renderId);
    $this->assertInstanceOf(VideoRenderResult::class, $firstResult);
    $this->assertTrue($firstResult->isPending());
    $this->assertSame(VideoRenderStatus::Pending, $firstResult->status);
    $this->assertNull($firstResult->videoContent);

    // Second poll should be completed
    $secondResult = $provider->poll($renderId);
    $this->assertTrue($secondResult->isCompleted());
    $this->assertSame(VideoRenderStatus::Completed, $secondResult->status);
    $this->assertNotEmpty($secondResult->videoContent);
  }

  public function test_replay_audio_provider_produces_voiceover_and_one_sound_effect(): void
  {
    $provider = new ReplayAudioProvider();

    $this->assertInstanceOf(AudioProviderInterface::class, $provider);

    $audio = $provider->generate('15s product teaser, rainy Tokyo, neon', 'cinematic neon rain');

    $this->assertInstanceOf(AudioResult::class, $audio);
    $this->assertStringStartsWith('RIFF', $audio->voiceover);
    $this->assertStringStartsWith('RIFF', $audio->soundEffect);
    $this->assertNotSame($audio->voiceover, $audio->soundEffect);
  }

  public function test_replay_judge_returns_passing_rubric_shape(): void
  {
    $judge = new ReplayJudge();

    $this->assertInstanceOf(JudgeInterface::class, $judge);

    $imageVerdict = $judge->judgeImage('keyframes/shot_1.png', 'A neon alley', 'cyberpunk');
    $this->assertInstanceOf(JudgementResult::class, $imageVerdict);
    $this->assertSame(JudgementVerdict::Pass, $imageVerdict->verdict());
    $this->assertTrue($imageVerdict->isPass());
    $this->assertArrayHasKey('prompt_adherence', $imageVerdict->scores);
    $this->assertArrayHasKey('anatomy', $imageVerdict->scores);
    $this->assertArrayHasKey('object_integrity', $imageVerdict->scores);
    $this->assertEmpty($imageVerdict->hardFailTags);

    $videoVerdict = $judge->judgeVideo('videos/shot_1.mp4', 'A neon alley', 'cyberpunk');
    $this->assertSame(JudgementVerdict::Pass, $videoVerdict->verdict());
    $this->assertArrayHasKey('temporal_consistency', $videoVerdict->scores);
  }

  public function test_replay_judge_throws_when_its_fixture_is_missing(): void
  {
    // Arrange
    $judge = new ReplayJudge('/nonexistent/judge.json');

    // Assert
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Replay judge fixture is missing');

    // Act
    $judge->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
  }

  public function test_replay_judge_throws_when_a_scripted_failure_is_malformed(): void
  {
    // Arrange
    $path = tempnam(sys_get_temp_dir(), 'judge');
    file_put_contents($path, json_encode([
      'video' => [
        'fail_when_prompt_starts_with' => 'Shot 2:',
        'pass_when_prompt_contains' => 'Simplify only this failure',
        'failure' => ['reason' => 'No scores or tags.'],
      ],
    ]));
    $judge = new ReplayJudge($path);

    // Assert
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('malformed [video] entry');

    // Act
    try
    {
      $judge->judgeVideo('videos/shot_2.mp4', 'Shot 2: neon sign');
    }
    finally
    {
      unlink($path);
    }
  }

  public function test_judgement_result_fails_on_low_score_or_hard_fail_tag(): void
  {
    $passing = new JudgementResult(
      scores: [
        'prompt_adherence' => 4,
        'anatomy' => 3,
        'object_integrity' => 5,
        'temporal_consistency' => 4,
      ],
      hardFailTags: [],
      reason: 'Looks consistent',
    );
    $this->assertSame(JudgementVerdict::Pass, $passing->verdict());
    $this->assertTrue($passing->isPass());

    $attributes = $passing->toModelAttributes(42, JudgementStage::Video);
    $this->assertSame(42, $attributes['shot_id']);
    $this->assertSame('video', $attributes['stage']);
    $this->assertSame('pass', $attributes['verdict']);

    $failingScore = new JudgementResult(
      scores: [
        'prompt_adherence' => 4,
        'anatomy' => 2,
        'object_integrity' => 5,
      ],
      hardFailTags: [],
      reason: 'Left hand has six fingers',
    );
    $this->assertSame(JudgementVerdict::Fail, $failingScore->verdict());
    $this->assertFalse($failingScore->isPass());

    $failingTag = new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
      ],
      hardFailTags: ['anatomy'],
      reason: 'Anatomy issue flagged',
    );
    $this->assertSame(JudgementVerdict::Fail, $failingTag->verdict());
  }

  public function test_ffmpeg_motion_video_provider_validates_keyframe_existence(): void
  {
    Storage::fake('s3');

    $provider = new FfmpegMotionVideoProvider();

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Keyframe not found at S3 path [missing.png].');

    $provider->submit('prompt', 'missing.png');
  }

  public function test_ffmpeg_motion_video_provider_polls_pending_then_generates_video(): void
  {
    Storage::fake('s3');
    Storage::disk('s3')->put('keyframes/shot_1.png', (new ReplayImageProvider())->generateImage('prompt'));

    $provider = new FfmpegMotionVideoProvider();
    $renderId = $provider->submit('A neon alley', 'keyframes/shot_1.png');

    // Poll 1: reports pending
    $poll1 = $provider->poll($renderId);
    $this->assertTrue($poll1->isPending());

    // Poll 2: executes ffmpeg and returns completed MP4
    $poll2 = $provider->poll($renderId);
    $this->assertTrue($poll2->isCompleted());
    $this->assertNotEmpty($poll2->videoContent);
  }

  public function test_ffmpeg_motion_video_provider_reports_failure_honestly(): void
  {
    Storage::fake('s3');
    // Store corrupt non-image bytes
    Storage::disk('s3')->put('keyframes/corrupt.png', 'NOT_AN_IMAGE');

    $provider = new FfmpegMotionVideoProvider();
    $renderId = $provider->submit('prompt', 'keyframes/corrupt.png');

    $provider->poll($renderId); // Advance past pending
    $result = $provider->poll($renderId);

    $this->assertTrue($result->isFailed());
    $this->assertStringContainsString('Invalid or corrupt keyframe image data', (string) $result->error);
  }

  public function test_unsupported_provider_names_throw_invalid_argument_exception(): void
  {
    config(['creative.image' => 'unsupported_image']);
    $this->expectException(InvalidArgumentException::class);
    app(ImageProviderInterface::class);
  }
}
