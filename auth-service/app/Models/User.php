<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ✅ Add this import
use Tymon\JWTAuth\Contracts\JWTSubject;

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

    // ✅ JWT: Get identifier stored in subject claim
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(3)
                ->withHeaders([
                    'X-Gateway-Secret' => env('GATEWAY_SECRET')
                ])
                ->get(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/users/' . $this->id . '/claims');
                
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'roles' => $data['roles'] ?? [],
                    'permissions' => $data['permissions'] ?? []
                ];
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to fetch user constraints: ' . $e->getMessage());
        }

        return [
            'roles' => [],
            'permissions' => []
        ];
    }
}