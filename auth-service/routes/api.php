<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| All routes here are automatically prefixed with /api
| Example: http://localhost:8000/api/login
|--------------------------------------------------------------------------
*/

// 🔓 Public Routes
Route::post('/auth/validate', [AuthController::class, 'validateToken']); // Internal but exposed if gateway passes it. Gateway explicitly calls this.

Route::middleware(['gateway.secret'])->group(function () {
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});


// 🔐 Protected Routes (JWT Required)
Route::middleware('auth:api')->prefix('auth')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Session & History
    Route::get('/sessions', [AuthController::class, 'getSessions']);
    Route::delete('/sessions/{id}', [AuthController::class, 'revokeSession']);
    Route::get('/history', [AuthController::class, 'getLoginHistory']);

});
});