<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
  ->withRouting(
    web: __DIR__ . '/../routes/web.php',
    api: __DIR__ . '/../routes/api.php',
    apiPrefix: '',
    commands: __DIR__ . '/../routes/console.php',
    health: '/up',
  )
  ->withMiddleware(function (Middleware $middleware): void
  {
  })
  ->withExceptions(function (Exceptions $exceptions): void
  {
    $exceptions->shouldRenderJsonWhen(
      fn (Request $request) => $request->is('briefs*') || $request->expectsJson(),
    );

    $exceptions->render(function (ValidationException $e, Request $request)
    {
      if ($request->is('briefs*') || $request->expectsJson())
      {
        return response()->json([
          'success' => false,
          'error' => [
            'message' => 'The given data was invalid.',
            'details' => $e->errors(),
          ],
        ], 422);
      }
    });

    $exceptions->render(function (NotFoundHttpException $e, Request $request)
    {
      if ($request->is('briefs*') || $request->expectsJson())
      {
        return response()->json([
          'success' => false,
          'error' => [
            'message' => 'Resource not found.',
          ],
        ], 404);
      }
    });
  })->create();
