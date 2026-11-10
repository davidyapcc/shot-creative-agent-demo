<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Brief;
use App\Services\BriefSubmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefController extends Controller
{
    public function store(Request $request, BriefSubmitter $submitter): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => BriefSubmitter::PROMPT_RULES,
        ]);

        $brief = $submitter->submit($validated['prompt']);

        return response()->json([
            'success' => true,
            'data' => [
                'brief' => $brief,
            ],
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $brief = Brief::with(['shots.judgements'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'brief' => $brief,
            ],
        ]);
    }
}
