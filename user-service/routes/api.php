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
    Route::post('/', [RolePermissionController::class, 'createRole']);
    Route::delete('/{id}', [RolePermissionController::class, 'deleteRole']);
    Route::post('/{id}/permissions', [RolePermissionController::class, 'assignPermissionsToRole']);
});

Route::prefix('permissions')->group(function () {
    Route::get('/', [RolePermissionController::class, 'getPermissions']);
    Route::post('/', [RolePermissionController::class, 'createPermission']);
    Route::delete('/{id}', [RolePermissionController::class, 'deletePermission']);
});

});
