<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OrganizationResource — Standardized API response for Organization objects.
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'domain'      => $this->domain,
            'description' => $this->description,
            'logo_url'    => $this->logo_url,
            'plan'        => $this->plan,
            'is_active'   => (bool) $this->is_active,
            'settings'    => $this->settings,

            'owner'       => $this->when(
                $this->relationLoaded('owner'),
                fn() => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null
            ),

            'users_count' => $this->when(isset($this->users_count), fn() => $this->users_count),
            'teams_count' => $this->when(isset($this->teams_count), fn() => $this->teams_count),

            'teams'       => $this->when(
                $this->relationLoaded('teams'),
                fn() => $this->teams->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])
            ),

            'created_at'  => $this->created_at->toIso8601String(),
            'updated_at'  => $this->updated_at->toIso8601String(),
        ];
    }
}
