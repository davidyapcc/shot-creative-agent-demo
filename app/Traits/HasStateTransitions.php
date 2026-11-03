<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\InvalidStateTransitionException;
use BackedEnum;

trait HasStateTransitions
{
  /**
   * Transition the entity state or throw if invalid.
   */
  public function transitionTo(BackedEnum $target): void
  {
    if (!($target instanceof ($this->state::class)) || !$this->state->canTransitionTo($target))
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
