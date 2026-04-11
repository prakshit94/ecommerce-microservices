<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\TeamController;

/*
|--------------------------------------------------------------------------
| User Service API Routes — Enterprise Edition
|--------------------------------------------------------------------------
| All routes require the X-Gateway-Secret header.
| Permission checks are done via CheckPermission middleware (X-User-Permissions header).
|
| Route structure:
|   /api/users/{id}/claims         — internal (auth-service only)
|   /api/v1/users/*                — user CRUD + RBAC assignments
|   /api/v1/roles/*                — role management
|   /api/v1/permissions/*          — permission management
|   /api/v1/organizations/*        — org management
|   /api/v1/organizations/{id}/teams/* — team management
|--------------------------------------------------------------------------
*/

// ─── INTERNAL ENDPOINT (no permission check — auth-service to user-service) ───
Route::middleware('gateway.secret')
    ->get('/users/{id}/claims', [UserController::class, 'claims'])
    ->name('users.claims');

// ─── ALL VERSIONED ROUTES ────────────────────────────────────────────────────
Route::middleware('gateway.secret')->prefix('v1')->group(function () {

    // ── USER MANAGEMENT ───────────────────────────────────────────────────
    Route::prefix('users')->group(function () {
        Route::middleware('permission:user-list')->get('/', [UserController::class, 'index'])->name('users.index');
        Route::middleware('permission:user-view')->get('/{id}', [UserController::class, 'show'])->name('users.show');
        Route::middleware('permission:user-create')->post('/', [UserController::class, 'store'])->name('users.store');
        Route::middleware('permission:user-update')->put('/{id}', [UserController::class, 'update'])->name('users.update');
        Route::middleware('permission:user-update')->patch('/{id}/status', [UserController::class, 'updateStatus'])->name('users.status');
        Route::middleware('permission:user-delete')->delete('/{id}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::middleware('permission:role-assign')->post('/bulk-assign-role', [UserController::class, 'bulkAssignRole'])->name('users.bulk-role');

        // Per-user RBAC
        Route::prefix('{id}')->group(function () {
            Route::middleware('permission:role-assign')->group(function () {
                Route::get('/roles', [RolePermissionController::class, 'getUserRoles'])->name('users.roles.index');
                Route::post('/roles', [RolePermissionController::class, 'assignRoles'])->name('users.roles.assign');
            });
            Route::middleware('permission:permission-assign')->group(function () {
                Route::get('/permissions', [RolePermissionController::class, 'getUserPermissions'])->name('users.permissions.index');
                Route::post('/permissions', [RolePermissionController::class, 'assignPermissions'])->name('users.permissions.assign');
            });
        });
    });

    // ── ROLE MANAGEMENT ───────────────────────────────────────────────────
    Route::prefix('roles')->group(function () {
        Route::middleware('permission:role-list')->get('/', [RolePermissionController::class, 'getRoles'])->name('roles.index');
        Route::middleware('permission:role-view')->get('/{id}', [RolePermissionController::class, 'showRole'])->name('roles.show');
        Route::middleware('permission:role-create')->post('/', [RolePermissionController::class, 'createRole'])->name('roles.store');
        Route::middleware('permission:role-update')->put('/{id}', [RolePermissionController::class, 'updateRole'])->name('roles.update');
        Route::middleware('permission:role-delete')->delete('/{id}', [RolePermissionController::class, 'deleteRole'])->name('roles.destroy');
        Route::middleware('permission:role-manage-permissions')->post('/{id}/permissions', [RolePermissionController::class, 'assignPermissionsToRole'])->name('roles.permissions.assign');
    });

    // ── PERMISSION MANAGEMENT ─────────────────────────────────────────────
    Route::prefix('permissions')->group(function () {
        Route::middleware('permission:permission-list')->get('/', [RolePermissionController::class, 'getPermissions'])->name('permissions.index');
        Route::middleware('permission:permission-view')->get('/{id}', [RolePermissionController::class, 'showPermission'])->name('permissions.show');
        Route::middleware('permission:permission-create')->post('/', [RolePermissionController::class, 'createPermission'])->name('permissions.store');
        Route::middleware('permission:permission-update')->put('/{id}', [RolePermissionController::class, 'updatePermission'])->name('permissions.update');
        Route::middleware('permission:permission-delete')->delete('/{id}', [RolePermissionController::class, 'deletePermission'])->name('permissions.destroy');
    });

    // ── ORGANIZATION MANAGEMENT ───────────────────────────────────────────
    Route::prefix('organizations')->group(function () {
        Route::middleware('permission:org-list')->get('/', [OrganizationController::class, 'index'])->name('orgs.index');
        Route::middleware('permission:org-view')->get('/{id}', [OrganizationController::class, 'show'])->name('orgs.show');
        Route::middleware('permission:org-view')->get('/{id}/users', [OrganizationController::class, 'users'])->name('orgs.users');
        Route::middleware('permission:org-create')->post('/', [OrganizationController::class, 'store'])->name('orgs.store');
        Route::middleware('permission:org-update')->put('/{id}', [OrganizationController::class, 'update'])->name('orgs.update');
        Route::middleware('permission:org-delete')->delete('/{id}', [OrganizationController::class, 'destroy'])->name('orgs.destroy');

        // Team management nested under organizations
        Route::prefix('/{orgId}/teams')->group(function () {
            Route::middleware('permission:team-list')->get('/', [TeamController::class, 'index'])->name('teams.index');
            Route::middleware('permission:team-view')->get('/{teamId}', [TeamController::class, 'show'])->name('teams.show');
            Route::middleware('permission:team-create')->post('/', [TeamController::class, 'store'])->name('teams.store');
            Route::middleware('permission:team-update')->put('/{teamId}', [TeamController::class, 'update'])->name('teams.update');
            Route::middleware('permission:team-delete')->delete('/{teamId}', [TeamController::class, 'destroy'])->name('teams.destroy');
            Route::middleware('permission:team-manage-members')->post('/{teamId}/members', [TeamController::class, 'addMember'])->name('teams.members.add');
            Route::middleware('permission:team-manage-members')->delete('/{teamId}/members/{userId}', [TeamController::class, 'removeMember'])->name('teams.members.remove');
        });
    });

});
