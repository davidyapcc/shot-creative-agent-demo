<?php

declare(strict_types=1);

namespace App\DTOs;

class VideoRenderResult
{
  public function __construct(
    public readonly string $status,
    public readonly ?string $videoContent = null,
    public readonly ?string $error = null,
  )
  {
  }

  public static function pending(): self
  {
    return new self(status: 'pending');
  }

  public static function completed(string $videoContent): self
  {
    return new self(
      status: 'completed',
      videoContent: $videoContent,
    );
  }

  public static function failed(string $error): self
  {
    return new self(
      status: 'failed',
      error: $error,
    );
  }

  public function isPending(): bool
  {
    return $this->status === 'pending';
  }

  public function isCompleted(): bool
  {
    return $this->status === 'completed';
  }

  public function isFailed(): bool
  {
    return $this->status === 'failed';
  }
}
