<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RoleResource — Standardized API response for Role objects.
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'guard_name'  => $this->guard_name,
            'permissions' => $this->when(
                $this->relationLoaded('permissions'),
                fn() => $this->permissions->map(fn($p) => [
                    'id'   => $p->id,
                    'name' => $p->name,
                ])
            ),
            'users_count' => $this->when(
                isset($this->users_count),
                fn() => $this->users_count
            ),
            'created_at'  => $this->created_at->toIso8601String(),
            'updated_at'  => $this->updated_at->toIso8601String(),
        ];
    }
}
