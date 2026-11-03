<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BriefState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefController extends Controller
{
  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'prompt' => ['required', 'string', 'min:3'],
    ]);

    $brief = Brief::create([
      'prompt' => $validated['prompt'],
      'state' => BriefState::Pending,
    ]);

    PlanBriefJob::dispatch($brief->id);

    return response()->json([
      'brief' => $brief,
    ], 202);
  }

  public function show(int $id): JsonResponse
  {
    $brief = Brief::with('shots')->findOrFail($id);

    return response()->json([
      'brief' => $brief,
    ]);
  }
}
