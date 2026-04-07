<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Cleanup existing RBAC data
        DB::statement('DELETE FROM model_has_permissions');
        DB::statement('DELETE FROM model_has_roles');
        DB::statement('DELETE FROM role_has_permissions');
        DB::statement('DELETE FROM roles');
        DB::statement('DELETE FROM permissions');

        // Create 18 Granular Permissions
        $allPermissions = [
            'user-list', 'user-view', 'user-create', 'user-update', 'user-delete',
            'role-list', 'role-view', 'role-create', 'role-update', 'role-delete', 'role-assign', 'role-manage-permissions',
            'permission-list', 'permission-view', 'permission-create', 'permission-update', 'permission-delete', 'permission-assign'
        ];

        foreach ($allPermissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // --- 1. ADMIN PERSONA ---
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Super Admin', 'password' => Hash::make('admin123')]
        );
        $adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($allPermissions);
        $adminUser->assignRole($adminRole);

        // --- 2. MANAGER PERSONA (User Management) ---
        $managerUser = User::updateOrCreate(
            ['email' => 'manager@example.com'],
            ['name' => 'Manager User', 'password' => Hash::make('password123')]
        );
        $managerRole = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions(['user-list', 'user-view', 'user-create', 'user-update']);
        $managerUser->assignRole($managerRole);

        // --- 3. EDITOR PERSONA (Role Management) ---
        $editorUser = User::updateOrCreate(
            ['email' => 'editor@example.com'],
            ['name' => 'Editor User', 'password' => Hash::make('password123')]
        );
        $editorRole = Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
        $editorRole->syncPermissions(['role-list', 'role-view', 'role-create', 'role-update']);
        $editorUser->assignRole($editorRole);

        // --- 4. VIEW PERSONA (Read-only) ---
        $viewerUser = User::updateOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Viewer User', 'password' => Hash::make('password123')]
        );
        $viewerRole = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewerRole->syncPermissions(['user-list', 'role-list', 'permission-list']);
        $viewerUser->assignRole($viewerRole);
    }
}
