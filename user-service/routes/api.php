<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RolePermissionController;

/*
|--------------------------------------------------------------------------
| Internal Microservice Communication Routes
|--------------------------------------------------------------------------
| These routes are typically hit directly by other microservices
| (e.g., auth-service getting info to pack into JWT).
*/
Route::middleware(['gateway.secret'])->group(function () {
    Route::get('/users/{id}/claims', [UserController::class, 'claims']);

/*
|--------------------------------------------------------------------------
| API Routes (Proxied by Gateway with X-User headers)
|--------------------------------------------------------------------------
| These endpoints are guarded at the Gateway. Trusting the Gateway means
| we rely on standard validation. In a robust setup, you might verify
| a signature or require X-Forwarded-Host to ensure internal traffic only.
*/

    // User Management
    Route::prefix('users')->group(function () {
        Route::middleware('permission:user-list')->get('/', [UserController::class, 'index']);
        Route::middleware('permission:user-create')->post('/', [UserController::class, 'store']);
        Route::middleware('permission:user-view')->get('/{id}', [UserController::class, 'show']);
        Route::middleware('permission:user-update')->put('/{id}', [UserController::class, 'update']);
        Route::middleware('permission:user-delete')->delete('/{id}', [UserController::class, 'destroy']);
        
        // RBAC User-Specific Assignments
        Route::prefix('{id}')->group(function () {
            Route::middleware('permission:role-assign')->group(function () {
                Route::get('/roles', [RolePermissionController::class, 'getUserRoles']);
                Route::post('/roles', [RolePermissionController::class, 'assignRoles']);
            });
            Route::middleware('permission:permission-assign')->group(function () {
                Route::get('/permissions', [RolePermissionController::class, 'getUserPermissions']);
                Route::post('/permissions', [RolePermissionController::class, 'assignPermissions']);
            });
        });
    });

    // Role Management
    Route::prefix('roles')->group(function () {
        Route::middleware('permission:role-list')->get('/', [RolePermissionController::class, 'getRoles']);
        Route::middleware('permission:role-view')->get('/{id}', [RolePermissionController::class, 'showRole']);
        Route::middleware('permission:role-create')->post('/', [RolePermissionController::class, 'createRole']);
        Route::middleware('permission:role-update')->put('/{id}', [RolePermissionController::class, 'updateRole']);
        Route::middleware('permission:role-delete')->delete('/{id}', [RolePermissionController::class, 'deleteRole']);
        Route::middleware('permission:role-manage-permissions')->post('/{id}/permissions', [RolePermissionController::class, 'assignPermissionsToRole']);
    });

    // Permission Management
    Route::prefix('permissions')->group(function () {
        Route::middleware('permission:permission-list')->get('/', [RolePermissionController::class, 'getPermissions']);
        Route::middleware('permission:permission-view')->get('/{id}', [RolePermissionController::class, 'showPermission']);
        Route::middleware('permission:permission-create')->post('/', [RolePermissionController::class, 'createPermission']);
        Route::middleware('permission:permission-update')->put('/{id}', [RolePermissionController::class, 'updatePermission']);
        Route::middleware('permission:permission-delete')->delete('/{id}', [RolePermissionController::class, 'deletePermission']);
    });

});
