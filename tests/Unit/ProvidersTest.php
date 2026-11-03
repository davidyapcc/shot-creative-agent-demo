<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Services\Providers\FfmpegMotionVideoProvider;
use App\Services\Providers\ReplayImageProvider;
use App\Services\Providers\ReplayJudge;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Support\Facades\Storage;
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
    $this->assertNull($firstResult->videoContent);

    // Second poll should be completed
    $secondResult = $provider->poll($renderId);
    $this->assertTrue($secondResult->isCompleted());
    $this->assertNotEmpty($secondResult->videoContent);
  }

  public function test_replay_judge_returns_passing_rubric_shape(): void
  {
    $judge = new ReplayJudge();

    $this->assertInstanceOf(JudgeInterface::class, $judge);

    $imageVerdict = $judge->judgeImage('keyframes/shot_1.png', 'A neon alley', 'cyberpunk');
    $this->assertInstanceOf(JudgementResult::class, $imageVerdict);
    $this->assertSame('pass', $imageVerdict->verdict());
    $this->assertTrue($imageVerdict->isPass());
    $this->assertArrayHasKey('prompt_adherence', $imageVerdict->scores);
    $this->assertArrayHasKey('anatomy', $imageVerdict->scores);
    $this->assertArrayHasKey('object_integrity', $imageVerdict->scores);
    $this->assertEmpty($imageVerdict->hardFailTags);

    $videoVerdict = $judge->judgeVideo('videos/shot_1.mp4', 'A neon alley', 'cyberpunk');
    $this->assertSame('pass', $videoVerdict->verdict());
    $this->assertArrayHasKey('temporal_consistency', $videoVerdict->scores);
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
    $this->assertSame('pass', $passing->verdict());
    $this->assertTrue($passing->isPass());

    $failingScore = new JudgementResult(
      scores: [
        'prompt_adherence' => 4,
        'anatomy' => 2,
        'object_integrity' => 5,
      ],
      hardFailTags: [],
      reason: 'Left hand has six fingers',
    );
    $this->assertSame('fail', $failingScore->verdict());
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
    $this->assertSame('fail', $failingTag->verdict());
  }

  public function test_ffmpeg_motion_video_provider_implements_interface(): void
  {
    $provider = new FfmpegMotionVideoProvider();

    $this->assertInstanceOf(VideoProviderInterface::class, $provider);
  }

  public function test_ffmpeg_motion_video_provider_submits_and_polls_with_keyframe(): void
  {
    Storage::fake('s3');
    Storage::disk('s3')->put('keyframes/shot_1.png', (new ReplayImageProvider())->generateImage('prompt'));

    $provider = new FfmpegMotionVideoProvider();
    $renderId = $provider->submit('A neon alley', 'keyframes/shot_1.png');

    $result = $provider->poll($renderId);
    $this->assertTrue($result->isCompleted());
    $this->assertNotEmpty($result->videoContent);
  }
}
