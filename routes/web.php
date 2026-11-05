<?php

declare(strict_types=1);

use App\Http\Controllers\MediaController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): View {
    return view('welcome');
});

Route::middleware('throttle:120,1')->get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*');
