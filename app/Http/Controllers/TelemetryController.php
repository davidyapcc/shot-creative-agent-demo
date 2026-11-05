<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telemetry;
use Illuminate\Http\JsonResponse;

class TelemetryController extends Controller
{
    public function show(Telemetry $telemetry): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $telemetry->snapshot(),
        ]);
    }
}
