<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Services\AuthClaimsService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Auth Service User Model
 *
 * Handles JWT authentication, MFA tracking, account locking, and
 * Redis-cached JWT custom claims (no live HTTP call on token generation).
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'locked_until',
        'failed_login_attempts',
        'mfa_enabled',
        'mfa_secret',
        'mfa_backup_codes',
        'mfa_verified',
        'last_login_at',
        'timezone',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_backup_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'      => 'datetime',
            'last_login_at'          => 'datetime',
            'locked_until'           => 'datetime',
            'password'               => 'hashed',
            'is_active'              => 'boolean',
            'mfa_enabled'            => 'boolean',
            'mfa_verified'           => 'boolean',
            'mfa_backup_codes'       => 'array',
            'failed_login_attempts'  => 'integer',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JWT Interface
    // ─────────────────────────────────────────────────────────────────────────

    /** JWT: identifier stored in the 'sub' claim */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * JWT: Custom claims — roles & permissions are fetched via Redis-cached service.
     * This eliminates the per-login HTTP call to user-service, which caused N+1 scale issues.
     */
    public function getJWTCustomClaims(): array
    {
        try {
            /** @var AuthClaimsService $claimsService */
            $claimsService = app(AuthClaimsService::class);
            $jwtTtl = config('jwt.ttl', 60) * 60; // JWT TTL in seconds

            $claims = $claimsService->getClaims($this->id, $jwtTtl);

            return [
                'roles'       => $claims['roles'] ?? [],
                'permissions' => $claims['permissions'] ?? [],
                'is_active'   => $this->is_active,
                'mfa_enabled' => $this->mfa_enabled,
                'timezone'    => $this->timezone ?? 'UTC',
            ];
        } catch (\Exception $e) {
            Log::error('getJWTCustomClaims: failed — ' . $e->getMessage());
            return [
                'roles'       => [],
                'permissions' => [],
                'is_active'   => $this->is_active,
                'mfa_enabled' => $this->mfa_enabled,
                'timezone'    => $this->timezone ?? 'UTC',
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Account Lock Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Check if the account is currently locked. */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Increment failed login attempts and apply progressive lock if threshold reached. */
    public function recordFailedLogin(): void
    {
        $attempts = $this->failed_login_attempts + 1;
        $update   = ['failed_login_attempts' => $attempts];

        // Progressive lockout: 5 → 15min, 10 → 1hr, 15+ → 24hr
        if ($attempts >= 15) {
            $update['locked_until'] = Carbon::now()->addHours(24);
        } elseif ($attempts >= 10) {
            $update['locked_until'] = Carbon::now()->addHour();
        } elseif ($attempts >= 5) {
            $update['locked_until'] = Carbon::now()->addMinutes(15);
        }

        $this->update($update);
    }

    /** Reset failed attempts and unlock the account after successful login. */
    public function recordSuccessfulLogin(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => Carbon::now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relations
    // ─────────────────────────────────────────────────────────────────────────

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function loginHistories()
    {
        return $this->hasMany(LoginHistory::class);
    }
}