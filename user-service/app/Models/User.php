<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

/**
 * User Service User Model
 *
 * Handles RBAC (roles + permissions via Spatie), organization membership,
 * team membership, and structured audit logging.
 * Guard is 'api' to align with JWT/API-first architecture.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * Spatie roles/permissions use 'api' guard in an API-first service.
     */
    protected string $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'status',
        'is_super_admin',
        'phone',
        'profile_photo_url',
        'timezone',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relations
    // ─────────────────────────────────────────────────────────────────────────

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('role_in_team')
            ->withTimestamps();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Audit Logging (model observer pattern)
    // ─────────────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::created(fn($u) => self::logAction('created', $u));
        static::updated(fn($u) => self::logAction('updated', $u));
        static::deleted(fn($u) => self::logAction('deleted', $u));
    }

    protected static function logAction(string $event, User $model): void
    {
        try {
            AuditLog::create([
                'user_id'        => Request::header('X-User-Id'),
                'event'          => $event,
                'auditable_type' => self::class,
                'auditable_id'   => $model->id,
                'old_values'     => $event === 'updated'
                    ? array_intersect_key($model->getOriginal(), $model->getChanges())
                    : null,
                'new_values'     => $event === 'deleted'
                    ? null
                    : ($model->getChanges() ?: $model->getAttributes()),
                'ip_address'     => Request::ip(),
                'user_agent'     => Request::userAgent(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Audit log failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }
}
