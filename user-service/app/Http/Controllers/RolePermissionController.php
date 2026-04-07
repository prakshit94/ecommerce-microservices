<?php

namespace App\Http\Controllers;

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

    public function createRole(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:roles']);
        $role = Role::create(['name' => $request->name]);
        return response()->json($role, 201);
    }

    public function deleteRole($id)
    {
        Role::findOrFail($id)->delete();
        return response()->json(null, 204);
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

    public function createPermission(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:permissions']);
        $permission = Permission::create(['name' => $request->name]);
        return response()->json($permission, 201);
    }

    public function deletePermission($id)
    {
        Permission::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
