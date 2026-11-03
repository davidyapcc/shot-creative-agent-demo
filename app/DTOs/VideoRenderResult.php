<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\VideoRenderStatus;

class VideoRenderResult
{
  public function __construct(
    public readonly VideoRenderStatus $status,
    public readonly ?string $videoContent = null,
    public readonly ?string $error = null,
  )
  {
  }

  public static function pending(): self
  {
    return new self(status: VideoRenderStatus::Pending);
  }

  public static function completed(string $videoContent): self
  {
    return new self(
      status: VideoRenderStatus::Completed,
      videoContent: $videoContent,
    );
  }

  public static function failed(string $error): self
  {
    return new self(
      status: VideoRenderStatus::Failed,
      error: $error,
    );
  }

  public function isPending(): bool
  {
    return $this->status === VideoRenderStatus::Pending;
  }

  public function isCompleted(): bool
  {
    return $this->status === VideoRenderStatus::Completed;
  }

  public function isFailed(): bool
  {
    return $this->status === VideoRenderStatus::Failed;
  }
}
