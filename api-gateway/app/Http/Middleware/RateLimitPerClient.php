<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * RateLimitPerClient Middleware
 *
 * Applies per-user (authenticated) or per-IP (public) rate limits using Redis.
 * Attaches standard rate limit headers to all responses.
 *
 * Usage: Route::middleware('rate.client:60,1') — 60 requests per 1 minute
 */
class RateLimitPerClient
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        // Use user ID if authenticated (via gateway header), else IP
        $userId  = $request->header('X-User-Id');
        $key     = $userId ? "user:{$userId}" : "ip:{$request->ip()}";
        $limiterKey = "rate_limit:{$key}";

        $response = RateLimiter::attempt(
            $limiterKey,
            $maxAttempts,
            function () use ($request, $next) {
                return $next($request);
            },
            $decayMinutes * 60
        );

        if ($response === false) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'error'       => 'Too many requests. Please slow down.',
                'code'        => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'X-RateLimit-Limit'     => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset'     => now()->addSeconds($retryAfter)->timestamp,
                'Retry-After'           => $retryAfter,
            ]);
        }

        // Add rate limit headers to successful responses
        $remaining = RateLimiter::remaining($limiterKey, $maxAttempts);

        return $response->withHeaders([
            'X-RateLimit-Limit'     => $maxAttempts,
            'X-RateLimit-Remaining' => max(0, $remaining),
            'X-RateLimit-Reset'     => now()->addMinutes($decayMinutes)->timestamp,
        ]);
    }
}
