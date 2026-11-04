<?php

declare(strict_types=1);

use App\Models\Brief;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('briefs.{id}', function ($user = null, int $id): bool
{
  return Brief::where('id', $id)->exists();
});
