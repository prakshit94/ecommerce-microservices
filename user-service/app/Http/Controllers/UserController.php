<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PermissionCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * UserController — Enterprise User Management
 *
 * Handles full CRUD, status management, bulk role assignment,
 * JWT claims endpoint for auth-service, and standardized API Resource responses.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly PermissionCacheService $permissionCache,
    ) {}

    /**
     * List all users with pagination and optional filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['roles', 'organization'])
            ->withCount(['roles']);

        // Optional filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('organization_id')) {
            $query->forOrganization((int) $request->organization_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $users = $query->paginate($request->input('per_page', 20));

        return UserResource::collection($users);
    }

    /**
     * Show a single user.
     */
    public function show(int $id): UserResource
    {
        $user = User::with(['roles', 'permissions', 'organization', 'teams'])->findOrFail($id);
        return new UserResource($user);
    }

    /**
     * Internal endpoint: Get JWT claims (roles + permissions) for auth-service.
     * Called by auth-service to populate JWT custom claims via Redis cache.
     */
    public function claims(int $id): JsonResponse
    {
        $claims = $this->permissionCache->getUserClaims($id);

        return response()->json([
            'roles'       => $claims['roles'],
            'permissions' => $claims['permissions'],
        ]);
    }

    /**
     * Create a new user (internal sync from auth-service or admin action).
     */
    public function store(Request $request): UserResource
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|string|email|max:255|unique:users',
            'password'        => 'required|string|min:8',
            'organization_id' => 'sometimes|exists:organizations,id',
            'timezone'        => 'sometimes|string|max:64',
            'status'          => 'sometimes|in:active,inactive,suspended,pending_verification',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user = User::create($validated);

        return new UserResource($user->load('roles'));
    }

    /**
     * Update a user's profile.
     */
    public function update(Request $request, int $id): UserResource
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'email'            => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password'         => 'sometimes|string|min:8',
            'phone'            => 'sometimes|nullable|string|max:30',
            'profile_photo_url'=> 'sometimes|nullable|string|max:500',
            'timezone'         => 'sometimes|string|max:64',
            'organization_id'  => 'sometimes|nullable|exists:organizations,id',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
            // Invalidate permission/claims cache on password update
            $this->permissionCache->invalidateUser($user->id);
        }

        $user->update($validated);

        return new UserResource($user->load('roles', 'permissions'));
    }

    /**
     * Update user status (activate/deactivate/suspend).
     * Accepts either:
     *   { "status": "active" | "inactive" | "suspended" | "pending_verification" }
     *   { "is_active": true | false }  — convenience alias (maps to active/inactive)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'    => 'sometimes|in:active,inactive,suspended,pending_verification',
            'is_active' => 'sometimes|boolean',
        ]);

        // Map is_active boolean to status string if provided
        $status = $request->input('status');
        if (is_null($status) && $request->has('is_active')) {
            $status = $request->boolean('is_active') ? 'active' : 'inactive';
        }

        if (is_null($status)) {
            return response()->json([
                'error' => 'Provide either "status" or "is_active" field.',
            ], 422);
        }

        $user = User::findOrFail($id);
        $user->update(['status' => $status]);

        // Invalidate cache so active status propagates
        $this->permissionCache->invalidateUser($user->id);

        return response()->json([
            'message'   => 'User status updated successfully.',
            'is_active' => in_array($status, ['active']),
            'status'    => $status,
            'user'      => new UserResource($user),
        ]);
    }


    /**
     * Soft-delete a user.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $userId = $user->id;

        $this->permissionCache->invalidateUser($userId);
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
            'id'      => $userId,
        ]);
    }

    /**
     * Bulk assign a role to multiple users.
     */
    public function bulkAssignRole(Request $request): JsonResponse
    {
        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'role'     => 'required|string|exists:roles,name',
        ]);

        $users   = User::whereIn('id', $request->user_ids)->get();
        $updated = [];

        foreach ($users as $user) {
            $user->syncRoles([$request->role]);
            $this->permissionCache->invalidateUser($user->id);
            $updated[] = $user->id;
        }

        return response()->json([
            'message'      => 'Role assigned to users successfully.',
            'role'         => $request->role,
            'updated_users'=> $updated,
        ]);
    }
}
