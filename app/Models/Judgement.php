<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Judgement extends Model
{
  use HasFactory;

  protected $fillable = [
    'shot_id',
    'stage',
    'scores',
    'hard_fail_tags',
    'reason',
    'verdict',
  ];

  protected function casts(): array
  {
    return [
      'scores' => 'array',
      'hard_fail_tags' => 'array',
    ];
  }

  public function shot(): BelongsTo
  {
    return $this->belongsTo(Shot::class);
  }
}
