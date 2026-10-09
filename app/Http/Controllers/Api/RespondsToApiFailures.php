<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait RespondsToApiFailures
{
    protected function apiFailure(Throwable $error, string $message): JsonResponse
    {
        if ($error instanceof ValidationException || $error instanceof ModelNotFoundException ||
            $error instanceof HttpResponseException || $error instanceof HttpExceptionInterface) {
            throw $error;
        }

        $errorId = (string) Str::uuid();
        Log::error('API operation failed', [
            'error_id' => $errorId,
            'exception_class' => $error::class,
        ]);
        report($error);

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error_id' => $errorId,
        ], 500);
    }
}
