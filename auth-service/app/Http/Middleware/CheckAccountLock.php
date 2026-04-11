<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckAccountLock Middleware
 *
 * Validates that the authenticated user's account is active and not locked.
 * Applied to protected JWT routes to prevent locked users from using valid tokens.
 */
class CheckAccountLock
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'error' => 'Account is deactivated. Contact support.',
                'code'  => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        if ($user->isLocked()) {
            return response()->json([
                'error'     => 'Account is temporarily locked.',
                'unlock_at' => $user->locked_until?->toIso8601String(),
                'code'      => 'ACCOUNT_LOCKED',
            ], 423);
        }

        return $next($request);
    }
}
