<?php

declare(strict_types=1);

use App\Http\Controllers\BriefController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): View
{
  return view('welcome');
});

Route::post('/briefs', [BriefController::class, 'store']);
Route::get('/briefs/{id}', [BriefController::class, 'show']);
