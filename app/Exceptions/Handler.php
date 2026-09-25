<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        if (!$request->expectsJson() && !$request->is('api/*')) {
            return parent::render($request, $exception);
        }

        if ($exception instanceof ValidationException) {
            return response()->json([
                'message' => 'The submitted data is invalid.',
                'errors' => $exception->errors(),
            ], $exception->status);
        }

        if ($exception instanceof AuthenticationException) {
            return response()->json([
                'message' => 'Authentication is required for this action.',
            ], 401);
        }

        if ($exception instanceof ThrottleRequestsException) {
            return response()->json([
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        if ($exception instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'The requested resource was not found.',
            ], 404);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'The request could not be completed.',
            ], $exception->getStatusCode());
        }

        return response()->json([
            'message' => app()->hasDebugModeEnabled()
                ? $exception->getMessage()
                : 'Server error. Please try again later.',
        ], 500);
    }
}
