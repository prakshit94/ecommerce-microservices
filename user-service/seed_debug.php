<?php
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

// Cleanup
DB::statement('DELETE FROM model_has_permissions');
DB::statement('DELETE FROM model_has_roles');
DB::statement('DELETE FROM role_has_permissions');
DB::statement('DELETE FROM roles');
DB::statement('DELETE FROM permissions');
DB::statement('DELETE FROM users');

// Ensure User 1 exists (Force ID 1)
DB::table('users')->insert([
    'id' => 1,
    'email' => 'admin@example.com', 
    'name' => 'Super Admin', 
    'password' => Hash::make('admin123'),
    'created_at' => now(),
    'updated_at' => now(),
]);

$user = User::find(1);

// Ensure Permissions exist
$perms = ['view-users', 'manage-users', 'manage-roles', 'manage-permissions'];
foreach ($perms as $p) {
    Permission::create(['name' => $p, 'guard_name' => 'web']);
}

// Ensure Role exists and has permissions
$role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
$role->syncPermissions($perms);

// Assign role to user
$user->assignRole($role);

echo "DEBUG SEED COMPLETE\n";
echo "User ID: " . $user->id . "\n";
echo "Roles: " . implode(', ', $user->getRoleNames()->toArray()) . "\n";
echo "Permissions: " . implode(', ', $user->getAllPermissions()->pluck('name')->toArray()) . "\n";
