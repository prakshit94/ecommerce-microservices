<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\PermissionCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RolePermissionController — Enterprise RBAC Management
 *
 * Full CRUD for Roles and Permissions using Spatie v6.
 * Fixed guard_name to 'api' (was incorrectly 'web').
 * Includes bulk permission assignment and permission cache invalidation.
 */
class RolePermissionController extends Controller
{
    private string $guard = 'api';

    public function __construct(
        private readonly PermissionCacheService $permissionCache,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // ROLES
    // ─────────────────────────────────────────────────────────────────────────

    public function getRoles(): JsonResponse
    {
        $roles = Role::with('permissions')
            ->withCount('users')
            ->where('guard_name', $this->guard)
            ->get();

        return response()->json(RoleResource::collection($roles));
    }

    public function showRole(int $id): JsonResponse
    {
        $role = Role::with('permissions')
            ->withCount('users')
            ->where('guard_name', $this->guard)
            ->findOrFail($id);

        return response()->json(new RoleResource($role));
    }

    public function createRole(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:roles,name',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        try {
            $role = Role::create([
                'name'       => $request->name,
                'guard_name' => $this->guard,
            ]);

            if ($request->has('permissions')) {
                $role->syncPermissions($request->permissions);
            }

            $this->permissionCache->invalidateAll();

            return response()->json(new RoleResource($role->load('permissions')), 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create role.', 'details' => $e->getMessage()], 500);
        }
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $role = Role::where('guard_name', $this->guard)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $role->id,
        ]);

        $role->update(['name' => $request->name]);
        $this->permissionCache->invalidateAll();

        return response()->json(new RoleResource($role->load('permissions')));
    }

    public function deleteRole(int $id): JsonResponse
    {
        $role = Role::where('guard_name', $this->guard)->findOrFail($id);
        $roleId = $role->id;
        $role->delete();
        $this->permissionCache->invalidateAll();

        return response()->json(['message' => 'Role deleted successfully.', 'id' => $roleId]);
    }

    public function assignPermissionsToRole(Request $request, int $roleId): JsonResponse
    {
        $request->validate(['permissions' => 'required|array', 'permissions.*' => 'string']);
        $role = Role::where('guard_name', $this->guard)->findOrFail($roleId);
        $role->syncPermissions($request->permissions);
        $this->permissionCache->invalidateAll();

        return response()->json(new RoleResource($role->load('permissions')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PERMISSIONS
    // ─────────────────────────────────────────────────────────────────────────

    public function getPermissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', $this->guard)
            ->orderBy('name')
            ->get()
            ->groupBy(function ($p) {
                // Group by prefix (e.g., 'user-list' → group 'user')
                return explode('-', $p->name)[0];
            })
            ->map(fn($group) => $group->map(fn($p) => ['id' => $p->id, 'name' => $p->name]));

        return response()->json(['permissions' => $permissions]);
    }

    public function showPermission(int $id): JsonResponse
    {
        $permission = Permission::where('guard_name', $this->guard)->findOrFail($id);
        return response()->json($permission);
    }

    public function createPermission(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|unique:permissions,name']);

        try {
            $permission = Permission::create([
                'name'       => $request->name,
                'guard_name' => $this->guard,
            ]);

            $this->permissionCache->invalidateAll();

            return response()->json($permission, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create permission.', 'details' => $e->getMessage()], 500);
        }
    }

    public function updatePermission(Request $request, int $id): JsonResponse
    {
        $permission = Permission::where('guard_name', $this->guard)->findOrFail($id);
        $request->validate(['name' => 'required|string|unique:permissions,name,' . $permission->id]);
        $permission->update(['name' => $request->name]);
        $this->permissionCache->invalidateAll();

        return response()->json($permission);
    }

    public function deletePermission(int $id): JsonResponse
    {
        $permission = Permission::where('guard_name', $this->guard)->findOrFail($id);
        $permissionId = $permission->id;
        $permission->delete();
        $this->permissionCache->invalidateAll();

        return response()->json(['message' => 'Permission deleted.', 'id' => $permissionId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // USER ROLE & PERMISSION ASSIGNMENTS
    // ─────────────────────────────────────────────────────────────────────────

    public function getUserRoles(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        return response()->json(RoleResource::collection($user->roles));
    }

    public function assignRoles(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'roles'   => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user = User::findOrFail($userId);
        $user->syncRoles($request->roles);
        $this->permissionCache->invalidateUser($userId);

        return response()->json([
            'message' => 'Roles assigned successfully.',
            'user_id' => $userId,
            'roles'   => RoleResource::collection($user->load('roles')->roles),
        ]);
    }

    public function getUserPermissions(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $permissions = $this->permissionCache->getUserPermissions($userId);

        return response()->json([
            'user_id'     => $userId,
            'permissions' => $permissions,
        ]);
    }

    public function assignPermissions(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $user = User::findOrFail($userId);
        $user->syncPermissions($request->permissions);
        $this->permissionCache->invalidateUser($userId);

        return response()->json([
            'message'     => 'Permissions assigned successfully.',
            'user_id'     => $userId,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
