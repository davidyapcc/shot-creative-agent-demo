<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BriefState;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brief extends Model
{
  use HasFactory;

  protected $fillable = [
    'prompt',
    'state',
    'style_anchor',
    'audio_path',
    'final_video_path',
    'budget_used',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'state' => BriefState::class,
      'metadata' => 'array',
      'budget_used' => 'integer',
    ];
  }

  public function shots(): HasMany
  {
    return $this->hasMany(Shot::class)->orderBy('sequence');
  }

  public function transitionTo(BriefState $target): void
  {
    if (!$this->state->canTransitionTo($target))
    {
      throw InvalidStateTransitionException::fromStates(
        $this->state->value,
        $target->value,
        'Brief',
      );
    }

    $this->update(['state' => $target]);
  }
}
