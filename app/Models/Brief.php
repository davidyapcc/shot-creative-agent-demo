<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\BriefStateChanged;
use App\Traits\HasStateTransitions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Brief extends Model
{
  use HasFactory;
  use HasStateTransitions;

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

  protected static function booted(): void
  {
    static::updated(function (Brief $brief): void
    {
      if ($brief->wasChanged('state'))
      {
        BriefStateChanged::dispatch($brief->id, $brief->getOriginal('state'), $brief->state);
      }
    });
  }

  public function shots(): HasMany
  {
    return $this->hasMany(Shot::class)->orderBy('sequence');
  }

  /**
   * Whether at least one shot passed its video judge, so there is a clip to mux.
   */
  public function hasPassingClip(): bool
  {
    return $this->shots()
      ->where('state', ShotState::Done)
      ->whereNotNull('video_path')
      ->where('video_path', '!=', '')
      ->exists();
  }

  /**
   * Fail the brief and any shot still in flight.
   */
  public function failWithInFlightShots(): void
  {
    if (!$this->state->canTransitionTo(BriefState::Failed))
    {
      return;
    }

    DB::transaction(function (): void
    {
      $this->transitionTo(BriefState::Failed);

      $this->shots
        ->filter(fn (Shot $shot) => $shot->state->canTransitionTo(ShotState::Failed))
        ->each(fn (Shot $shot) => $shot->transitionTo(ShotState::Failed));
    });
  }
}
