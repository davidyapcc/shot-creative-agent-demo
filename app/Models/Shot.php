<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShotState;
use App\Traits\HasStateTransitions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Shot extends Model
{
  use HasFactory;
  use HasStateTransitions;

  protected $fillable = [
    'brief_id',
    'sequence',
    'prompt',
    'state',
    'keyframe_path',
    'video_path',
    'attempts',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'state' => ShotState::class,
      'sequence' => 'integer',
      'attempts' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function brief(): BelongsTo
  {
    return $this->belongsTo(Brief::class);
  }

  public function judgements(): HasMany
  {
    return $this->hasMany(Judgement::class)->orderBy('id');
  }

  /**
   * Move on after a provider call, unless the budget stop failed this shot
   * while the call was in flight. Returns false when the result must be dropped.
   */
  public function advanceUnlessStopped(ShotState $target): bool
  {
    $isAdvanced = DB::transaction(function () use ($target): bool
    {
      $locked = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

      if ($locked->state === ShotState::Failed)
      {
        return false;
      }

      $locked->transitionTo($target);

      return true;
    });

    $this->refresh();

    return $isAdvanced;
  }
}
