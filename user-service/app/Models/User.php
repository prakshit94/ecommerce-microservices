<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
        static::created(function ($user) {
            self::logAction('created', $user);
        });

        static::updated(function ($user) {
            self::logAction('updated', $user);
        });

        static::deleted(function ($user) {
            self::logAction('deleted', $user);
        });
    }

    protected static function logAction($event, $model)
    {
        try {
            AuditLog::create([
                'user_id' => request()->header('X-User-Id'), // Forwarded by Gateway
                'event' => $event,
                'auditable_type' => get_class($model),
                'auditable_id' => $model->id,
                'old_values' => $event === 'updated' ? array_intersect_key($model->getOriginal(), $model->getChanges()) : null,
                'new_values' => $event === 'deleted' ? null : ($model->getChanges() ?: $model->getAttributes()),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Audit logging failed: ' . $e->getMessage());
        }
    }
}
