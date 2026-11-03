<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\InvalidStateTransitionException;

trait HasStateTransitions
{
  /**
   * Transition the entity state or throw if invalid.
   */
  public function transitionTo(mixed $target): void
  {
    if (!$this->state->canTransitionTo($target))
    {
      throw InvalidStateTransitionException::fromStates(
        $this->state->value,
        $target->value,
        class_basename($this),
      );
    }

    $this->update(['state' => $target]);
  }
}
