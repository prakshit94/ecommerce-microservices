<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

/**
 * AdminUserSeeder — Enterprise RBAC Seed Data
 *
 * Seeds 50+ permissions across 8 resource groups, 7 enterprise roles,
 * a default organization, and 6 personas for testing all permission levels.
 * All permissions/roles use 'api' guard (not 'web').
 */
class AdminUserSeeder extends Seeder
{
    private string $guard = 'api';

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Clean RBAC tables (preserve users)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('model_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('role_has_permissions')->truncate();
        DB::table('roles')->truncate();
        DB::table('permissions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ── 1. CREATE PERMISSIONS (50 granular permissions across 8 groups) ──
        $permissionGroups = [
            // User management
            'user'        => ['list', 'view', 'create', 'update', 'delete', 'impersonate'],
            // Role management
            'role'        => ['list', 'view', 'create', 'update', 'delete', 'assign', 'manage-permissions'],
            // Permission management
            'permission'  => ['list', 'view', 'create', 'update', 'delete', 'assign'],
            // Organization management
            'org'         => ['list', 'view', 'create', 'update', 'delete'],
            // Team management
            'team'        => ['list', 'view', 'create', 'update', 'delete', 'manage-members'],
            // Audit logs
            'audit'       => ['view', 'export'],
            // System (super-admin)
            'system'      => ['settings-view', 'settings-update', 'cache-clear', 'maintenance'],
        ];

        $allPermissions = [];
        foreach ($permissionGroups as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group}-{$action}";
                $perm = Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard]);
                $allPermissions[$name] = $perm;
            }
        }

        $this->command->info('✅ Created ' . count($allPermissions) . ' permissions.');

        // ── 2. CREATE DEFAULT ORGANIZATION ───────────────────────────────────
        $org = Organization::firstOrCreate(
            ['slug' => 'default-org'],
            [
                'name'        => 'Default Organization',
                'domain'      => 'example.com',
                'description' => 'Default organization for initial setup.',
                'plan'        => 'enterprise',
                'is_active'   => true,
            ]
        );

        // ── 3. CREATE ROLES & ASSIGN PERMISSIONS ─────────────────────────────

        // SUPER ADMIN — all permissions
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => $this->guard]);
        $superAdminRole->syncPermissions(array_keys($allPermissions));

        // ORG ADMIN — manages everything except system
        $orgAdminPerms = array_filter(array_keys($allPermissions), fn($p) => !str_starts_with($p, 'system-'));
        $orgAdminRole  = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => $this->guard]);
        $orgAdminRole->syncPermissions(array_values($orgAdminPerms));

        // TEAM LEAD — manages their team and views users
        $teamLeadRole = Role::firstOrCreate(['name' => 'team-lead', 'guard_name' => $this->guard]);
        $teamLeadRole->syncPermissions([
            'user-list', 'user-view',
            'team-list', 'team-view', 'team-update', 'team-manage-members',
            'role-list', 'role-view',
        ]);

        // USER MANAGER — full CRUD on users, roles, no system or org
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => $this->guard]);
        $managerRole->syncPermissions([
            'user-list', 'user-view', 'user-create', 'user-update',
            'role-list', 'role-view', 'role-assign',
            'permission-list', 'permission-view',
            'audit-view',
        ]);

        // DEVELOPER — view everything, manage permissions
        $developerRole = Role::firstOrCreate(['name' => 'developer', 'guard_name' => $this->guard]);
        $developerRole->syncPermissions([
            'user-list', 'user-view',
            'role-list', 'role-view',
            'permission-list', 'permission-view', 'permission-create', 'permission-update',
            'audit-view',
        ]);

        // SUPPORT — read-only on users + audit
        $supportRole = Role::firstOrCreate(['name' => 'support', 'guard_name' => $this->guard]);
        $supportRole->syncPermissions([
            'user-list', 'user-view',
            'audit-view',
        ]);

        // VIEWER — minimal read access
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $this->guard]);
        $viewerRole->syncPermissions([
            'user-list', 'role-list', 'permission-list',
        ]);

        // Legacy roles (for backward compatibility with existing tests)
        $adminRole   = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => $this->guard]);
        $adminRole->syncPermissions(array_keys($allPermissions));
        $manRole = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => $this->guard]);
        $manRole->syncPermissions(['user-list', 'user-view', 'user-create', 'user-update']);
        $editRole = Role::firstOrCreate(['name' => 'Editor', 'guard_name' => $this->guard]);
        $editRole->syncPermissions(['role-list', 'role-view', 'role-create', 'role-update']);
        $viewRole = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => $this->guard]);
        $viewRole->syncPermissions(['user-list', 'role-list', 'permission-list']);

        $this->command->info('✅ Created 11 roles with permissions.');

        // ── 4. CREATE TEST USERS ─────────────────────────────────────────────
        $users = [
            [
                'email'    => 'admin@example.com',
                'name'     => 'Super Admin',
                'password' => 'Admin@123456',
                'role'     => 'super-admin',
                'is_super_admin' => true,
            ],
            [
                'email'    => 'orgadmin@example.com',
                'name'     => 'Org Admin',
                'password' => 'OrgAdmin@123',
                'role'     => 'org-admin',
            ],
            [
                'email'    => 'manager@example.com',
                'name'     => 'Manager User',
                'password' => 'Manager@123',
                'role'     => 'manager',
            ],
            [
                'email'    => 'editor@example.com',
                'name'     => 'Editor User',
                'password' => 'Editor@1234',
                'role'     => 'developer',
            ],
            [
                'email'    => 'support@example.com',
                'name'     => 'Support User',
                'password' => 'Support@123',
                'role'     => 'support',
            ],
            [
                'email'    => 'viewer@example.com',
                'name'     => 'Viewer User',
                'password' => 'Viewer@1234',
                'role'     => 'viewer',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name'            => $userData['name'],
                    'password'        => Hash::make($userData['password']),
                    'organization_id' => $org->id,
                    'status'          => 'active',
                    'is_super_admin'  => $userData['is_super_admin'] ?? false,
                ]
            );
            $user->syncRoles([$userData['role']]);
        }

        $this->command->info('✅ Created 6 user personas.');
        $this->command->newLine();
        $this->command->line('  <fg=green>admin@example.com</>        / Admin@123456   → super-admin');
        $this->command->line('  <fg=green>orgadmin@example.com</>     / OrgAdmin@123   → org-admin');
        $this->command->line('  <fg=green>manager@example.com</>      / Manager@123    → manager');
        $this->command->line('  <fg=green>editor@example.com</>       / Editor@1234    → developer');
        $this->command->line('  <fg=green>support@example.com</>      / Support@123    → support');
        $this->command->line('  <fg=green>viewer@example.com</>       / Viewer@1234    → viewer');
    }
}
