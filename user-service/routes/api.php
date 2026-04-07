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

Route::apiResource('users', UserController::class);

Route::prefix('roles')->group(function () {
    Route::get('/', [RolePermissionController::class, 'getRoles']);
    Route::get('/{id}', [RolePermissionController::class, 'showRole']);
    Route::post('/', [RolePermissionController::class, 'createRole']);
    Route::put('/{id}', [RolePermissionController::class, 'updateRole']);
    Route::delete('/{id}', [RolePermissionController::class, 'deleteRole']);
    Route::post('/{id}/permissions', [RolePermissionController::class, 'assignPermissionsToRole']);
});

Route::prefix('permissions')->group(function () {
    Route::get('/', [RolePermissionController::class, 'getPermissions']);
    Route::get('/{id}', [RolePermissionController::class, 'showPermission']);
    Route::post('/', [RolePermissionController::class, 'createPermission']);
    Route::put('/{id}', [RolePermissionController::class, 'updatePermission']);
    Route::delete('/{id}', [RolePermissionController::class, 'deletePermission']);
});

Route::prefix('users/{id}')->group(function () {
    Route::get('/roles', [RolePermissionController::class, 'getUserRoles']);
    Route::post('/roles', [RolePermissionController::class, 'assignRoles']);
    Route::get('/permissions', [RolePermissionController::class, 'getUserPermissions']);
    Route::post('/permissions', [RolePermissionController::class, 'assignPermissions']);
});

});
