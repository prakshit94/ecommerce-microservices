<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * OrganizationController — Multi-Tenant Organization Management
 *
 * Full CRUD for organizations. Only accessible to super-admin or
 * users with appropriate permissions (enforced at route/middleware level).
 */
class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Organization::withCount(['users', 'teams'])->with('owner');

        if ($request->has('plan')) {
            $query->where('plan', $request->plan);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->has('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('domain', 'like', "%{$s}%"));
        }

        return OrganizationResource::collection($query->paginate($request->input('per_page', 20)));
    }

    public function show(int $id): OrganizationResource
    {
        $org = Organization::with(['owner', 'teams'])->withCount(['users', 'teams'])->findOrFail($id);
        return new OrganizationResource($org);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'sometimes|string|max:100|unique:organizations,slug',
            'domain'      => 'sometimes|nullable|string|unique:organizations,domain',
            'description' => 'sometimes|nullable|string|max:1000',
            'logo_url'    => 'sometimes|nullable|string|max:500',
            'plan'        => 'sometimes|in:free,starter,professional,enterprise',
            'is_active'   => 'sometimes|boolean',
            'owner_id'    => 'sometimes|exists:users,id',
            'settings'    => 'sometimes|array',
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $org = Organization::create($validated);

        return response()->json(['data' => new OrganizationResource($org->load('owner'))], 201);
    }

    public function update(Request $request, int $id): OrganizationResource
    {
        $org = Organization::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'sometimes|string|max:100|unique:organizations,slug,' . $org->id,
            'domain'      => 'sometimes|nullable|string|unique:organizations,domain,' . $org->id,
            'description' => 'sometimes|nullable|string|max:1000',
            'logo_url'    => 'sometimes|nullable|string|max:500',
            'plan'        => 'sometimes|in:free,starter,professional,enterprise',
            'is_active'   => 'sometimes|boolean',
            'owner_id'    => 'sometimes|exists:users,id',
            'settings'    => 'sometimes|array',
        ]);

        $org->update($validated);

        return new OrganizationResource($org->load('owner'));
    }

    public function destroy(int $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $org->delete();

        return response()->json(['message' => 'Organization deleted.', 'id' => $id]);
    }

    /**
     * List all users in an organization.
     */
    public function users(Request $request, int $id): JsonResponse
    {
        $org   = Organization::findOrFail($id);
        $users = $org->users()
            ->with('roles')
            ->paginate($request->input('per_page', 20));

        return response()->json($users);
    }
}
