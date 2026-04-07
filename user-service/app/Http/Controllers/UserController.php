<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with('roles', 'permissions')->get());
    }

    public function show($id)
    {
        $user = User::with('roles', 'permissions')->findOrFail($id);
        return response()->json($user);
    }

    // This route is called internally by auth-service
    public function claims($id)
    {
        $user = User::findOrFail($id);
        
        return response()->json([
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name')
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'roles' => 'array'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        if (isset($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return response()->json($user->load('roles', 'permissions'), 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'roles' => 'array'
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        if (isset($request->roles)) {
            $user->syncRoles($request->roles);
        }

        return response()->json($user->load('roles', 'permissions'));
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $id = $user->id;
        $user->delete();
        return response()->json([
            'message' => 'User deleted successfully',
            'id' => $id
        ]);
    }
}
