<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireGatewaySecret
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = env('GATEWAY_SECRET');
        if (!$secret || $request->header('X-Gateway-Secret') !== $secret) {
            return response()->json(['error' => 'Forbidden. Invalid Gateway Secret.'], 403);
        }

        return $next($request);
    }
}
