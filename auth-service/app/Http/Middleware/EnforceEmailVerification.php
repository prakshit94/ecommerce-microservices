<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceEmailVerification Middleware
 *
 * Blocks access for users who haven't verified their email address.
 * Apply on routes that require verified accounts (e.g., sensitive actions).
 */
class EnforceEmailVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json([
                'error'   => 'Email address not verified. Please check your inbox.',
                'code'    => 'EMAIL_NOT_VERIFIED',
                'hint'    => 'Use POST /v1/auth/email/resend to get a new verification link.',
            ], 403);
        }

        return $next($request);
    }
}
