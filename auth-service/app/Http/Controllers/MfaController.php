<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Services\AuthClaimsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PragmaRX\Google2FA\Google2FA;

/**
 * MfaController — TOTP-Based Multi-Factor Authentication
 *
 * Supports: setup (QR code), verify & activate, disable, challenge (login step 2), backup codes.
 * Uses Google Authenticator compatible TOTP (RFC 6238).
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly AuthClaimsService $claimsService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // SETUP — Generate TOTP secret & QR code (user is authenticated)
    // ─────────────────────────────────────────────────────────────────────────

    public function setup(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->mfa_enabled) {
            return response()->json(['error' => 'MFA is already enabled for this account.'], 409);
        }

        $google2fa = new Google2FA();
        $secret    = $google2fa->generateSecretKey(32);

        // Store secret temporarily (not yet active until verified)
        $user->update(['mfa_secret' => $secret, 'mfa_verified' => false, 'mfa_enabled' => false]);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'EcommerceApp'),
            $user->email,
            $secret
        );

        return response()->json([
            'message'     => 'Scan the QR code with your authenticator app, then verify with your TOTP code.',
            'secret'      => $secret,
            'qr_code_url' => $qrCodeUrl,
            'manual_entry'=> 'Use this secret in your authenticator app if QR scanning fails.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFY & ACTIVATE MFA
    // ─────────────────────────────────────────────────────────────────────────

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|digits:6']);

        /** @var User $user */
        $user = auth()->user();

        if (!$user->mfa_secret) {
            return response()->json(['error' => 'MFA setup not initiated. Call /auth/mfa/setup first.'], 400);
        }

        if ($user->mfa_enabled) {
            return response()->json(['error' => 'MFA is already verified and active.'], 409);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->mfa_secret, $request->code);

        if (!$valid) {
            return response()->json(['error' => 'Invalid TOTP code. Please try again.'], 422);
        }

        // Generate backup codes
        $backupCodes = collect(range(1, 10))->map(fn() => Str::upper(Str::random(4) . '-' . Str::random(4)))->toArray();

        $user->update([
            'mfa_enabled'      => true,
            'mfa_verified'     => true,
            'mfa_backup_codes' => $backupCodes,
        ]);

        return response()->json([
            'message'      => 'MFA enabled successfully. Store your backup codes securely — they will not be shown again.',
            'backup_codes' => $backupCodes,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHALLENGE — Step 2 of login (verify TOTP after password was correct)
    // ─────────────────────────────────────────────────────────────────────────

    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'code'      => 'required|string', // TOTP or backup code
            'device_type' => 'sometimes|string|in:web,ios,android,api,desktop',
            'device_name' => 'sometimes|string|max:100',
        ]);

        $challengeKey  = "mfa:challenge:{$request->mfa_token}";
        $challengeData = Cache::get($challengeKey);

        if (!$challengeData) {
            return response()->json([
                'error'   => 'MFA challenge expired or invalid. Please login again.',
                'code'    => 'MFA_CHALLENGE_EXPIRED',
            ], 401);
        }

        $user = User::findOrFail($challengeData['user_id']);

        if (!$user->mfa_enabled || !$user->mfa_secret) {
            return response()->json(['error' => 'MFA is not configured for this account.'], 400);
        }

        $google2fa = new Google2FA();
        $isValidTotp = $google2fa->verifyKey($user->mfa_secret, $request->code);

        // Check backup codes if TOTP fails
        $isValidBackup = false;
        if (!$isValidTotp && $user->mfa_backup_codes) {
            $backupIndex = array_search($request->code, $user->mfa_backup_codes ?? []);
            if ($backupIndex !== false) {
                $isValidBackup = true;
                // Remove used backup code
                $codes = $user->mfa_backup_codes;
                array_splice($codes, $backupIndex, 1);
                $user->update(['mfa_backup_codes' => $codes]);
            }
        }

        if (!$isValidTotp && !$isValidBackup) {
            return response()->json(['error' => 'Invalid MFA code.'], 422);
        }

        // Consume challenge token
        Cache::forget($challengeKey);

        // Issue full JWT
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        // Record session with mfa_verified = true
        $jwtPayload = \Tymon\JWTAuth\Facades\JWTAuth::setToken($token)->getPayload();
        UserSession::create([
            'user_id'        => $user->id,
            'jti'            => $jwtPayload->get('jti'),
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'device_type'    => $request->input('device_type', 'web'),
            'device_name'    => $request->input('device_name'),
            'device_fingerprint' => $request->header('X-Device-Fingerprint'),
            'mfa_verified'   => true,
            'is_revoked'     => false,
            'expires_at'     => Carbon::createFromTimestamp($jwtPayload->get('exp')),
        ]);

        $user->recordSuccessfulLogin();

        return response()->json([
            'message'    => 'MFA verified. Login successful.',
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'mfa_method' => $isValidBackup ? 'backup_code' : 'totp',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISABLE MFA (requires password confirmation)
    // ─────────────────────────────────────────────────────────────────────────

    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'code'     => 'required|string|digits:6',
        ]);

        /** @var User $user */
        $user = auth()->user();

        if (!$user->mfa_enabled) {
            return response()->json(['error' => 'MFA is not enabled.'], 400);
        }

        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Incorrect password.'], 422);
        }

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($user->mfa_secret, $request->code)) {
            return response()->json(['error' => 'Invalid TOTP code.'], 422);
        }

        $user->update([
            'mfa_enabled'      => false,
            'mfa_secret'       => null,
            'mfa_backup_codes' => null,
            'mfa_verified'     => false,
        ]);

        // Invalidate claims cache to update mfa_enabled in JWTs
        $this->claimsService->invalidateClaims($user->id);

        return response()->json(['message' => 'MFA has been disabled successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGENERATE BACKUP CODES
    // ─────────────────────────────────────────────────────────────────────────

    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|digits:6']);

        /** @var User $user */
        $user = auth()->user();

        if (!$user->mfa_enabled) {
            return response()->json(['error' => 'MFA is not enabled.'], 400);
        }

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($user->mfa_secret, $request->code)) {
            return response()->json(['error' => 'Invalid TOTP code.'], 422);
        }

        $backupCodes = collect(range(1, 10))->map(fn() => Str::upper(Str::random(4) . '-' . Str::random(4)))->toArray();
        $user->update(['mfa_backup_codes' => $backupCodes]);

        return response()->json([
            'message'      => 'Backup codes regenerated. Store them securely.',
            'backup_codes' => $backupCodes,
        ]);
    }
}
