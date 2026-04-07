<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    // === ROLES ===
    public function getRoles()
    {
        return response()->json(Role::with('permissions')->get());
    }

    public function showRole($id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        return response()->json($role);
    }

    public function createRole(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:roles']);
        $role = Role::create(['name' => $request->name]);
        return response()->json($role->load('permissions'), 201);
    }

    public function updateRole(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $request->validate(['name' => 'required|string|unique:roles,name,' . $role->id]);
        $role->update(['name' => $request->name]);
        return response()->json($role->load('permissions'));
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);
        $roleId = $role->id;
        $role->delete();
        return response()->json([
            'message' => 'Role deleted successfully',
            'id' => $roleId
        ]);
    }

    public function assignPermissionsToRole(Request $request, $roleId)
    {
        $request->validate(['permissions' => 'required|array']);
        $role = Role::findOrFail($roleId);
        $role->syncPermissions($request->permissions);
        
        return response()->json($role->load('permissions'));
    }

    // === PERMISSIONS ===
    public function getPermissions()
    {
        return response()->json(Permission::all());
    }

    public function showPermission($id)
    {
        $permission = Permission::findOrFail($id);
        return response()->json($permission);
    }

    public function createPermission(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:permissions']);
        $permission = Permission::create(['name' => $request->name]);
        return response()->json($permission, 201);
    }

    public function updatePermission(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);
        $request->validate(['name' => 'required|string|unique:permissions,name,' . $permission->id]);
        $permission->update(['name' => $request->name]);
        return response()->json($permission);
    }

    public function deletePermission($id)
    {
        $permission = Permission::findOrFail($id);
        $permissionId = $permission->id;
        $permission->delete();
        return response()->json([
            'message' => 'Permission deleted successfully',
            'id' => $permissionId
        ]);
    }

    // === USER ROLES & PERMISSIONS ===
    public function getUserRoles($userId)
    {
        $user = User::findOrFail($userId);
        return response()->json($user->roles);
    }

    public function assignRoles(Request $request, $userId)
    {
        $request->validate(['roles' => 'required|array']);
        $user = User::findOrFail($userId);
        $user->syncRoles($request->roles);
        return response()->json($user->load('roles'));
    }

    public function getUserPermissions($userId)
    {
        $user = User::findOrFail($userId);
        return response()->json($user->getAllPermissions());
    }

    public function assignPermissions(Request $request, $userId)
    {
        $request->validate(['permissions' => 'required|array']);
        $user = User::findOrFail($userId);
        $user->syncPermissions($request->permissions);
        return response()->json($user->load('permissions'));
    }
}
