<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders API errors as {success, data, error: {message, code, fields?}, meta}.
 */
class ApiExceptionRenderer
{
  private const CODES_BY_STATUS = [
    Response::HTTP_NOT_FOUND => 'not_found',
    Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
  ];

  public static function isApiRequest(Request $request): bool
  {
    return $request->is('briefs*') || $request->expectsJson();
  }

  public function __invoke(Throwable $e, Request $request): ?JsonResponse
  {
    if (!self::isApiRequest($request))
    {
      return null;
    }

    if ($e instanceof ValidationException)
    {
      return $this->respond(Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', 'The given data was invalid.', $e->errors());
    }

    if ($e instanceof HttpExceptionInterface)
    {
      $status = $e->getStatusCode();
      $code = self::CODES_BY_STATUS[$status] ?? 'http_error';

      return $this->respond($status, $code, Response::$statusTexts[$status] ?? 'HTTP error.')
        ->withHeaders($e->getHeaders());
    }

    return $this->respond(Response::HTTP_INTERNAL_SERVER_ERROR, 'server_error', 'Server error.');
  }

  /**
   * @param array<string, array<int, string>>|null $fields
   */
  private function respond(int $status, string $code, string $message, ?array $fields = null): JsonResponse
  {
    $error = ['message' => $message, 'code' => $code];

    if ($fields !== null)
    {
      $error['fields'] = $fields;
    }

    return response()->json([
      'success' => false,
      'data' => null,
      'error' => $error,
      'meta' => null,
    ], $status);
  }
}
