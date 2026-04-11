<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register custom middleware aliases
        $middleware->alias([
            'rate.client' => \App\Http\Middleware\RateLimitPerClient::class,
        ]);

        // Apply CORS and JSON handling for all API groups
        $middleware->appendToGroup('api', [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for gateway API routes
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*') || $request->is('v1/*') || $request->expectsJson();
        });

        // Unauthenticated → 401
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json([
                'error' => 'Unauthenticated.',
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        });

        // Validation → 422
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            return response()->json([
                'errors'  => $e->errors(),
                'message' => 'The given data was invalid.',
            ], 422);
        });

        // 404 → clean JSON
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'Endpoint not found.', 'code' => 'NOT_FOUND'], 404);
            }
        });

        // 503 upstream service failures
        $exceptions->render(function (\Illuminate\Http\Client\ConnectionException $e, $request) {
            return response()->json([
                'error' => 'A downstream service is temporarily unavailable.',
                'code'  => 'SERVICE_UNAVAILABLE',
            ], 503);
        });
    })->create();
