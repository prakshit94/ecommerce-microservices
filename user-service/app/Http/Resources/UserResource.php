<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource — Standardized API response for User objects.
 * Ensures consistent shape across all endpoints.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'email'            => $this->email,
            'status'           => $this->status,
            'is_super_admin'   => (bool) $this->is_super_admin,
            'phone'            => $this->phone,
            'profile_photo_url'=> $this->profile_photo_url,
            'timezone'         => $this->timezone ?? 'UTC',
            'email_verified'   => !is_null($this->email_verified_at),
            'last_login_at'    => $this->last_login_at?->toIso8601String(),

            // Organization (if loaded)
            'organization'     => $this->when(
                $this->relationLoaded('organization'),
                fn() => $this->organization ? [
                    'id'   => $this->organization->id,
                    'name' => $this->organization->name,
                    'slug' => $this->organization->slug,
                    'plan' => $this->organization->plan,
                ] : null
            ),

            // Teams (if loaded)
            'teams'            => $this->when(
                $this->relationLoaded('teams'),
                fn() => $this->teams->map(fn($t) => [
                    'id'           => $t->id,
                    'name'         => $t->name,
                    'role_in_team' => $t->pivot->role_in_team,
                ])
            ),

            // Roles (if loaded via Spatie)
            'roles'            => $this->when(
                $this->relationLoaded('roles'),
                fn() => $this->roles->map(fn($r) => [
                    'id'   => $r->id,
                    'name' => $r->name,
                ])
            ),

            // Permissions (from roles + direct, if loaded)
            'permissions'      => $this->when(
                $this->relationLoaded('permissions'),
                fn() => $this->getAllPermissions()->pluck('name')
            ),

            'created_at'       => $this->created_at->toIso8601String(),
            'updated_at'       => $this->updated_at->toIso8601String(),
        ];
    }
}
