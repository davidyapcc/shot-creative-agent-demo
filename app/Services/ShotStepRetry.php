<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class ShotStepRetry
{
  public const MAX_RETRIES = 2;

  public function __construct(
    private readonly AgentInterface $agent,
  )
  {
  }

  public function afterFailure(
    Shot $shot,
    JudgementResult $verdict,
    JudgementStage $stage,
    ?Batch $batch = null,
  ): void
  {
    $shot->loadMissing('brief');

    $step = $stage === JudgementStage::Image ? 'image' : 'video';
    // Snapshot before the charge refreshes the shot; the attempt log is built on it.
    $metadata = $shot->metadata ?? [];

    if ($this->hasUsedAllRetries($shot, $metadata, $step))
    {
      return;
    }

    if (!$this->chargeRewrite($shot))
    {
      return;
    }

    $this->rewriteAndLogAttempt($shot, $verdict, $metadata, $step);
    $this->retryStep($shot, $stage, $batch);
  }

  /**
   * Fail the shot once its retries for this step are spent.
   *
   * @param array<string, mixed> $metadata
   */
  private function hasUsedAllRetries(Shot $shot, array $metadata, string $step): bool
  {
    $used = (int) ($metadata['step_attempts'][$step] ?? 0);

    if ($used <= self::MAX_RETRIES)
    {
      return false;
    }

    if ($shot->state->canTransitionTo(ShotState::Failed))
    {
      $shot->transitionTo(ShotState::Failed);
    }

    return true;
  }

  /**
   * Meter the rewrite call. False when the budget stop fired or failed the shot.
   */
  private function chargeRewrite(Shot $shot): bool
  {
    if ($shot->brief !== null && !app(Budget::class)->chargeOrDegrade($shot->brief, 'agent', 'rewritePrompt'))
    {
      return false;
    }

    $shot->refresh();

    return $shot->state !== ShotState::Failed;
  }

  /**
   * @param array<string, mixed> $metadata
   */
  private function rewriteAndLogAttempt(Shot $shot, JudgementResult $verdict, array $metadata, string $step): void
  {
    $log = $metadata['prompt_attempts'][$step] ?? [];

    // The first rewrite overwrites shots.prompt, so keep the planned prompt
    // here; every later rewrite must start from the scene as planned.
    $originalPrompt = (string) ($metadata['original_prompt'] ?? $shot->prompt);
    $metadata['original_prompt'] = $originalPrompt;

    $rewritten = $this->rewrite($shot, $verdict, $originalPrompt, $log);

    $log[] = [
      'prompt' => $shot->prompt,
      'reason' => $verdict->reason,
    ];
    $metadata['prompt_attempts'][$step] = $log;

    $shot->update([
      'prompt' => $rewritten,
      'metadata' => $metadata,
    ]);
  }

  /**
   * @param list<array<string, mixed>> $previousAttempts
   */
  private function rewrite(Shot $shot, JudgementResult $verdict, string $originalPrompt, array $previousAttempts): string
  {
    return $this->agent->rewritePrompt([
      'brief' => (string) $shot->brief?->prompt,
      'original_prompt' => $originalPrompt,
      'failed_scores' => $verdict->scores,
      'reason' => $verdict->reason,
      'previous_attempts' => $previousAttempts,
    ]);
  }

  private function retryStep(Shot $shot, JudgementStage $stage, ?Batch $batch): void
  {
    if ($stage === JudgementStage::Image)
    {
      $this->dispatch([
        new GenerateKeyframeJob($shot->id, $shot->brief_id),
        new JudgeKeyframeJob($shot->id, $shot->brief_id),
      ], $batch);

      return;
    }

    $this->resubmitVideo($shot, $batch);
  }

  private function resubmitVideo(Shot $shot, ?Batch $batch): void
  {
    $poll = app(ShotVideoSubmitter::class)->submit($shot, app(VideoProviderInterface::class));

    if ($poll === null)
    {
      return;
    }

    if ($batch !== null)
    {
      $batch->add([$poll]);

      return;
    }

    dispatch($poll);
  }

  /**
   * @param list<object> $jobs
   */
  private function dispatch(array $jobs, ?Batch $batch): void
  {
    if ($batch !== null)
    {
      $batch->add([$jobs]);

      return;
    }

    Bus::chain($jobs)->dispatch();
  }
}
