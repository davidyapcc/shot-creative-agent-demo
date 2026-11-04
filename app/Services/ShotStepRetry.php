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
    $metadata = $shot->metadata ?? [];
    $log = $metadata['prompt_attempts'][$step] ?? [];
    $used = (int) ($metadata['step_attempts'][$step] ?? 0);

    if ($used > self::MAX_RETRIES)
    {
      if ($shot->state->canTransitionTo(ShotState::Failed))
      {
        $shot->transitionTo(ShotState::Failed);
      }

      return;
    }

    if ($shot->brief !== null && !app(Budget::class)->chargeOrDegrade($shot->brief, 'agent', 'rewritePrompt'))
    {
      return;
    }

    $shot->refresh();

    if ($shot->state === ShotState::Failed)
    {
      return;
    }

    $rewritten = $this->agent->rewritePrompt([
      'brief' => (string) $shot->brief?->prompt,
      'original_prompt' => $shot->prompt,
      'failed_scores' => $verdict->scores,
      'reason' => $verdict->reason,
      'previous_attempts' => $log,
    ]);

    $log[] = [
      'prompt' => $shot->prompt,
      'reason' => $verdict->reason,
    ];
    $metadata['prompt_attempts'][$step] = $log;

    $shot->update([
      'prompt' => $rewritten,
      'metadata' => $metadata,
    ]);

    if ($stage === JudgementStage::Image)
    {
      $this->dispatch([
        new GenerateKeyframeJob($shot->id, $shot->brief_id),
        new JudgeKeyframeJob($shot->id, $shot->brief_id),
      ], $batch);

      return;
    }

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
