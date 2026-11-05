<?php

declare(strict_types=1);

use App\Http\Controllers\BriefController;
use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    Route::post('/briefs', [BriefController::class, 'store']);
    Route::get('/briefs/{id}', [BriefController::class, 'show']);
    Route::get('/telemetry', [TelemetryController::class, 'show']);
});
