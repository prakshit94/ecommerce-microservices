<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * UserSession model — tracks active JWT sessions with device info.
 */
class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'jti',
        'ip_address',
        'user_agent',
        'device_type',
        'device_name',
        'device_fingerprint',
        'country_code',
        'is_trusted_device',
        'mfa_verified',
        'is_revoked',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_revoked'       => 'boolean',
            'is_trusted_device'=> 'boolean',
            'mfa_verified'     => 'boolean',
            'expires_at'       => 'datetime',
            'last_used_at'     => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Check if this session is still valid (not revoked and not expired). */
    public function isValid(): bool
    {
        return !$this->is_revoked && $this->expires_at->isFuture();
    }
}
