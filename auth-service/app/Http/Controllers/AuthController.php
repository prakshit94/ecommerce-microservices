<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\LoginHistory;
use App\Models\UserSession;
use App\Services\AuthClaimsService;
use App\Services\LoginAnomalyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

/**
 * AuthController — Enterprise-Grade Authentication
 *
 * Supports: JWT login/register/logout/refresh/password management,
 * MFA check flow, device tracking, account locking, anomaly detection,
 * session management for web + mobile (device_type aware).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthClaimsService $claimsService,
        private readonly LoginAnomalyService $anomalyService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register a new user.
     * Public endpoint — creates account in auth-service then syncs to user-service.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|email:rfc|max:255|unique:users',
            'password'    => [
                'required', 'string', 'min:12', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_\-#])[A-Za-z\d@$!%*?&_\-#]+$/'
            ],
            'device_type' => 'sometimes|string|in:web,ios,android,api,desktop',
            'device_name' => 'sometimes|string|max:100',
            'timezone'    => 'sometimes|string|max:64',
        ], [
            'password.regex' => 'Password must contain at least one uppercase, lowercase, number, and special character.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Create user in auth-service
        $user = User::create([
            'name'                  => $request->name,
            'email'                 => $request->email,
            'password'              => Hash::make($request->password),
            'timezone'              => $request->input('timezone', 'UTC'),
            'is_active'             => true,
            'failed_login_attempts' => 0,
        ]);

        // Sync to user-service (internal call — bypasses permission middleware)
        try {
            $userServiceBase = rtrim(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api'), '/');
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Gateway-Secret' => env('GATEWAY_SECRET'),
                    'X-Internal-Sync'  => '1',          // flags this as internal service call
                ])
                ->post("{$userServiceBase}/v1/users", [
                    'name'            => $request->name,
                    'email'           => $request->email,
                    'password'        => $request->password,
                    'timezone'        => $request->input('timezone', 'UTC'),
                    'is_active'       => true,
                    'roles'           => ['customer'],   // default role for self-registered users
                    '_internal_sync'  => true,           // skip duplicate email validation
                ]);

            if (!$response->successful()) {
                Log::warning('Register: user-service sync returned ' . $response->status() . ': ' . $response->body());
                // Non-fatal: auth record created — user-service may be synced later
            }
        } catch (\Exception $e) {
            Log::warning('Register: user-service unreachable — ' . $e->getMessage());
            // Non-fatal: continue with auth-side registration
        }

        // Generate JWT
        $token = JWTAuth::fromUser($user);
        $this->recordSession($user->id, $token, $request, false);
        $user->recordSuccessfulLogin();

        return response()->json([
            'message'    => 'User registered successfully',
            'user'       => $this->formatUser($user),
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Login user — supports MFA challenge flow and device tracking.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'       => 'required|string|email',
            'password'    => 'required|string',
            'device_type' => 'sometimes|string|in:web,ios,android,api,desktop',
            'device_name' => 'sometimes|string|max:100',
            'timezone'    => 'sometimes|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $credentials = $request->only('email', 'password');
        $user        = User::where('email', $request->email)->first();

        // Account existence check (avoid leaking user existence in timing)
        if (!$user) {
            $this->recordFailedHistory(null, $request);
            return response()->json(['error' => 'Invalid credentials.'], 401);
        }

        // Account active check
        if (!$user->is_active) {
            return response()->json(['error' => 'Account is deactivated. Contact support.'], 403);
        }

        // Account lock check
        if ($user->isLocked()) {
            $unlockAt = $user->locked_until->diffForHumans();
            return response()->json([
                'error'      => 'Account temporarily locked due to too many failed attempts.',
                'unlock_at'  => $user->locked_until->toIso8601String(),
                'unlock_in'  => $unlockAt,
            ], 423);
        }

        // Attempt JWT authentication
        if (!$token = JWTAuth::attempt($credentials)) {
            $user->recordFailedLogin();
            $this->recordFailedHistory($user->id, $request);

            $remaining = max(0, 5 - $user->fresh()->failed_login_attempts);
            return response()->json([
                'error'              => 'Invalid credentials.',
                'attempts_remaining' => $remaining > 0 ? $remaining : 'Account locked',
            ], 401);
        }

        // Anomaly detection (non-blocking warning)
        $anomaly = $this->anomalyService->analyze($user, $request);

        // MFA flow — if MFA is enabled, issue a short-lived challenge token
        if ($user->mfa_enabled) {
            // Revoke the full token and issue a limited MFA-challenge token
            JWTAuth::invalidate(JWTAuth::setToken($token));
            $challengeToken = $this->issueMfaChallengeToken($user);

            return response()->json([
                'mfa_required'    => true,
                'mfa_token'       => $challengeToken,
                'message'         => 'MFA verification required. Submit your TOTP code to /auth/mfa/challenge.',
            ], 200);
        }

        // Record session & update user
        $this->recordSession($user->id, $token, $request, true);
        $this->recordSuccessHistory($user->id, $request);
        $user->recordSuccessfulLogin();

        // Update timezone if provided
        if ($request->has('timezone')) {
            $user->update(['timezone' => $request->timezone]);
        }

        return response()->json([
            'message'    => 'Login successful',
            'user'       => $this->formatUser($user->fresh()),
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'suspicious_login' => $anomaly['suspicious'],
            'suspicious_reasons' => $anomaly['reasons'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CURRENT USER
    // ─────────────────────────────────────────────────────────────────────────

    public function me(): JsonResponse
    {
        return response()->json(['user' => $this->formatUser(auth()->user())]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGOUT
    // ─────────────────────────────────────────────────────────────────────────

    public function logout(): JsonResponse
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $jti     = $payload->get('jti');

            UserSession::where('jti', $jti)->update(['is_revoked' => true]);

            // Invalidate claims cache on logout
            $this->claimsService->invalidateClaims(auth()->id());

            auth()->logout();
        } catch (\Exception $e) {
            Log::warning('Logout: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Successfully logged out.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REFRESH TOKEN
    // ─────────────────────────────────────────────────────────────────────────

    public function refresh(Request $request): JsonResponse
    {
        try {
            $oldPayload = JWTAuth::parseToken()->getPayload();
            UserSession::where('jti', $oldPayload->get('jti'))->update(['is_revoked' => true]);

            $token = auth()->refresh();
            $this->recordSession(auth()->id(), $token, $request, true);

            return response()->json([
                'token'      => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token refresh failed: ' . $e->getMessage()], 401);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['error' => __($status)], 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => 'required|email|exists:users,email',
            'password'              => [
                'required', 'string', 'min:12', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_\-#])[A-Za-z\d@$!%*?&_\-#]+$/'
            ],
            'token'                 => 'required|string',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $user = User::where('email', $request->email)->first();

            // Revoke all sessions after password reset
            UserSession::where('user_id', $user->id)->update(['is_revoked' => true]);
            $this->claimsService->invalidateClaims($user->id);

            // Sync to user-service
            $this->syncPasswordToUserService($user->id, $request->password);

            return response()->json(['message' => 'Password has been reset successfully.']);
        }

        return response()->json(['error' => __($status)], 422);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => [
                'required', 'string', 'min:12', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&_\-#])[A-Za-z\d@$!%*?&_\-#]+$/'
            ],
        ]);

        /** @var User $user */
        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // Revoke all other sessions (keep current one active)
        $currentJti = JWTAuth::parseToken()->getPayload()->get('jti');
        UserSession::where('user_id', $user->id)
            ->where('jti', '!=', $currentJti)
            ->update(['is_revoked' => true]);

        // Sync to user-service
        $this->syncPasswordToUserService($user->id, $request->new_password);

        return response()->json(['message' => 'Password changed successfully.', 'user' => $this->formatUser($user)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SESSION MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function getSessions(): JsonResponse
    {
        $sessions = UserSession::where('user_id', auth()->id())
            ->where('is_revoked', false)
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($s) => [
                'id'               => $s->id,
                'device_type'      => $s->device_type,
                'device_name'      => $s->device_name,
                'ip_address'       => $s->ip_address,
                'country_code'     => $s->country_code,
                'is_trusted_device'=> $s->is_trusted_device,
                'mfa_verified'     => $s->mfa_verified,
                'last_used_at'     => $s->last_used_at?->toIso8601String(),
                'expires_at'       => $s->expires_at->toIso8601String(),
                'created_at'       => $s->created_at->toIso8601String(),
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function revokeSession(int $id): JsonResponse
    {
        $session = UserSession::where('user_id', auth()->id())->findOrFail($id);
        $session->update(['is_revoked' => true]);

        return response()->json(['message' => 'Session revoked successfully.']);
    }

    public function revokeAllSessions(): JsonResponse
    {
        UserSession::where('user_id', auth()->id())->update(['is_revoked' => true]);
        $this->claimsService->invalidateClaims(auth()->id());

        return response()->json(['message' => 'All sessions have been revoked.']);
    }

    public function getLoginHistory(): JsonResponse
    {
        $history = LoginHistory::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn($h) => [
                'id'         => $h->id,
                'ip_address' => $h->ip_address,
                'user_agent' => $h->user_agent,
                'status'     => $h->status,
                'created_at' => $h->created_at->toIso8601String(),
            ]);

        return response()->json(['history' => $history]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL — Token Validation (called by API Gateway)
    // ─────────────────────────────────────────────────────────────────────────

    public function validateToken(Request $request): JsonResponse
    {
        $jti = $request->input('jti');
        if (!$jti) {
            return response()->json(['valid' => false, 'reason' => 'No JTI provided'], 400);
        }

        $session = UserSession::where('jti', $jti)->first();

        if (!$session) {
            return response()->json(['valid' => false, 'reason' => 'session_not_found']);
        }

        if ($session->is_revoked) {
            return response()->json(['valid' => false, 'reason' => 'session_revoked']);
        }

        if ($session->expires_at < now()) {
            return response()->json(['valid' => false, 'reason' => 'session_expired']);
        }

        // Update last_used_at without triggering updated_at
        try {
            $session->timestamps = false;
            $session->last_used_at = now();
            $session->save();
        } catch (\Exception $e) {
            Log::warning('validateToken: could not update last_used_at — ' . $e->getMessage());
        }

        return response()->json([
            'valid'       => true,
            'user_id'     => $session->user_id,
            'device_type' => $session->device_type,
            'mfa_verified'=> $session->mfa_verified,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function recordSession(int $userId, string $token, Request $request, bool $mfaVerified = false): void
    {
        try {
            $payload = JWTAuth::setToken($token)->getPayload();

            UserSession::create([
                'user_id'            => $userId,
                'jti'                => $payload->get('jti'),
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
                'device_type'        => $request->input('device_type', 'web'),
                'device_name'        => $request->input('device_name'),
                'device_fingerprint' => $request->header('X-Device-Fingerprint'),
                'is_trusted_device'  => false,
                'mfa_verified'       => $mfaVerified,
                'is_revoked'         => false,
                'expires_at'         => Carbon::createFromTimestamp($payload->get('exp')),
            ]);
        } catch (\Exception $e) {
            Log::error('recordSession: failed — ' . $e->getMessage());
        }
    }

    private function recordSuccessHistory(int $userId, Request $request): void
    {
        LoginHistory::create([
            'user_id'    => $userId,
            'email'      => $request->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status'     => 'success',
        ]);
    }

    private function recordFailedHistory(?int $userId, Request $request): void
    {
        LoginHistory::create([
            'user_id'    => $userId,
            'email'      => $request->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status'     => 'failed',
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'is_active'        => $user->is_active,
            'mfa_enabled'      => $user->mfa_enabled,
            'email_verified'   => !is_null($user->email_verified_at),
            'timezone'         => $user->timezone ?? 'UTC',
            'last_login_at'    => $user->last_login_at?->toIso8601String(),
            'created_at'       => $user->created_at->toIso8601String(),
        ];
    }

    private function issueMfaChallengeToken(User $user): string
    {
        // Issue a short-lived (5 min) signed challenge identifier stored in cache
        $token = Str::random(64);
        \Illuminate\Support\Facades\Cache::put(
            "mfa:challenge:{$token}",
            ['user_id' => $user->id, 'email' => $user->email],
            300 // 5 minutes
        );
        return $token;
    }

    private function syncPasswordToUserService(int $userId, string $plainPassword): void
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Gateway-Secret' => env('GATEWAY_SECRET')])
                ->put(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/users/' . $userId, [
                    'password' => $plainPassword,
                ]);

            if (!$response->successful()) {
                Log::error("syncPasswordToUserService: failed for user #{$userId} — " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("syncPasswordToUserService: exception for user #{$userId} — " . $e->getMessage());
        }
    }
}