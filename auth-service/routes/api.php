<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MfaController;

/*
|--------------------------------------------------------------------------
| Auth Service API Routes — Enterprise Edition
|--------------------------------------------------------------------------
| All routes are prefixed with /api automatically.
| API versioning: /api/v1/auth/*
|
| Security layers:
|   - gateway.secret   : enforces X-Gateway-Secret header (internal use)
|   - throttle:*       : rate limiting per IP + per route
|   - auth:api         : JWT authentication (tymon/jwt-auth)
|   - account.lock     : blocks locked/inactive accounts even with valid JWT
|--------------------------------------------------------------------------
*/

// ─────────────────────────────────────────────────────────────────────────
// INTERNAL ENDPOINT — Called only by API Gateway (protected by gateway secret key)
// ─────────────────────────────────────────────────────────────────────────
Route::middleware('gateway.secret')
    ->post('/auth/validate', [AuthController::class, 'validateToken'])
    ->name('auth.validate');

// ─────────────────────────────────────────────────────────────────────────
// ALL GATEWAY-PASSING ROUTES (require gateway secret)
// ─────────────────────────────────────────────────────────────────────────
Route::middleware('gateway.secret')->prefix('v1')->group(function () {

    // ── PUBLIC ROUTES (no JWT required) ─────────────────────────────────
    Route::prefix('auth')->group(function () {

        // Registration — strict throttle to prevent spam (10 per minute)
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:30,1')
            ->name('auth.register');

        // Login — very strict throttle for brute force protection (5 per minute)
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:20,1')
            ->name('auth.login');

        // Password reset flow
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:10,1')
            ->name('auth.forgot-password');

        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:20,1')
            ->name('auth.reset-password');

        // MFA Challenge — Step 2 of login (public: user holds challenge token, not JWT)
        Route::post('/mfa/challenge', [MfaController::class, 'challenge'])
            ->middleware('throttle:30,1')
            ->name('auth.mfa.challenge');
    });

    // ── PROTECTED ROUTES (JWT + account active check) ───────────────────
    Route::middleware(['auth:api', 'account.lock'])->prefix('auth')->group(function () {

        // Profile
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');

        // Token lifecycle
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');

        // Password management
        Route::post('/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        // Session management
        Route::get('/sessions', [AuthController::class, 'getSessions'])->name('auth.sessions.index');
        Route::post('/sessions/revoke-all', [AuthController::class, 'revokeAllSessions'])->name('auth.sessions.revoke-all');
        Route::delete('/sessions/{id}', [AuthController::class, 'revokeSession'])->name('auth.sessions.revoke');

        // Login history
        Route::get('/history', [AuthController::class, 'getLoginHistory'])->name('auth.history');

        // ── MFA Management ───────────────────────────────────────────────
        Route::prefix('mfa')->group(function () {
            Route::post('/setup', [MfaController::class, 'setup'])->name('auth.mfa.setup');
            Route::post('/verify', [MfaController::class, 'verify'])->name('auth.mfa.verify');
            Route::post('/disable', [MfaController::class, 'disable'])->name('auth.mfa.disable');
            Route::post('/backup-codes/regenerate', [MfaController::class, 'regenerateBackupCodes'])->name('auth.mfa.backup-codes');
        });
    });
});
