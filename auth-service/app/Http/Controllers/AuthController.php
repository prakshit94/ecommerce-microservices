<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register new user
     */
    public function register(Request $request)
    {
        // ✅ Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // ✅ Create User in auth-service
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // ✅ Sync to user-service
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'X-Gateway-Secret' => env('GATEWAY_SECRET')
                ])
                ->post(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/users', [
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => $request->password,
                ]);

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::error('Failed to sync user to user-service: ' . $response->body());
                $user->delete();
                return response()->json(['error' => 'Registration failed: user profile sync error.'], 500);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to sync user to user-service: ' . $e->getMessage());
            $user->delete();
            return response()->json(['error' => 'Registration failed: user-service unavailable.'], 500);
        }

        // ✅ Generate Token after registration (optional but recommended)
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        // ✅ Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $credentials = $request->only('email', 'password');

        // ✅ Attempt login
        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'error' => 'Invalid credentials'
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'user' => auth()->user(),
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me()
    {
        return response()->json(auth()->user());
    }

    /**
     * Logout user (invalidate token)
     */
    public function logout()
    {
        auth()->logout();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Refresh token
     */
    public function refresh()
    {
        return response()->json([
            'token' => auth()->refresh(),
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60
        ]);
    }

    /**
     * Forgot Password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $status = \Illuminate\Support\Facades\Password::sendResetLink($request->only('email'));

        return $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['error' => __($status)], 422);
    }

    /**
     * Reset Password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6|confirmed',
            'token' => 'required|string',
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['error' => __($status)], 422);
    }

    /**
     * Change Password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Current password does not match.'], 422);
        }

        // Update locally
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        // Sync to user-service
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'X-Gateway-Secret' => env('GATEWAY_SECRET')
                ])
                ->put(env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/users/' . $user->id, [
                    'password' => $request->new_password,
                ]);

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::error('Failed to sync password change to user-service: ' . $response->body());
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to sync password change to user-service: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Password changed successfully.',
            'user' => $user
        ]);
    }
}