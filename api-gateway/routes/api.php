<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| API Gateway Routes — Enterprise Edition
|--------------------------------------------------------------------------
| The gateway is the SINGLE ENTRY POINT for all client traffic.
|
| Security pipeline for every request:
|   CORS → Rate Limiting → JWT Verification → Session Validation → Proxy
|
| Key design decisions:
|   - /v1/ prefix for all versioned API routes
|   - Public routes: register, login, forgot-password, reset-password, mfa/challenge
|   - Protected routes: everything else requires valid JWT
|   - Health endpoints: /health, /health/live, /health/ready (no auth)
|   - Internal endpoints (e.g., /claims) are blocked at gateway
|--------------------------------------------------------------------------
*/

// ─────────────────────────────────────────────────────────────────────────────
// INFRASTRUCTURE ROUTES — No auth, no rate limits (for monitoring systems)
// ─────────────────────────────────────────────────────────────────────────────
Route::get('/health', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/live', [HealthController::class, 'live'])->name('health.liveness');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.readiness');

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Proxy a request to a downstream microservice
// ─────────────────────────────────────────────────────────────────────────────
function proxyRequest(Request $request, string $baseUrl, string $path): Response
{
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

    // Forward all headers except Host and sensitive ones
    $headers = collect($request->header())
        ->mapWithKeys(fn($values, $key) => [$key => $values[0]])
        ->except(['host', 'x-gateway-secret', 'content-length'])
        ->toArray();

    // Inject gateway secret for microservice authentication
    $headers['X-Gateway-Secret'] = env('GATEWAY_SECRET');
    $headers['X-Forwarded-For']  = $request->ip();
    $headers['X-Real-IP']        = $request->ip();

    $client = Http::withoutVerifying()->withHeaders($headers)->timeout(10);

    if (str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
        // Handle file uploads
        foreach ($request->allFiles() as $key => $file) {
            if (!is_array($file)) {
                $client->attach($key, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }
        }
        $fields = [];
        foreach ($request->except(array_keys($request->allFiles())) as $k => $v) {
            $fields[$k] = is_array($v) ? json_encode($v) : $v;
        }
        $response = $client->send($request->method(), $url, ['multipart' => $fields]);
    } else {
        if ($ct = $request->header('Content-Type')) {
            $headers['Content-Type'] = $ct;
            $client = Http::withoutVerifying()->withHeaders($headers)->timeout(10);
        }
        $response = $client->send($request->method(), $url, ['body' => $request->getContent()]);
    }

    $responseHeaders = collect($response->headers())
        ->except(['Transfer-Encoding', 'transfer-encoding', 'x-powered-by'])
        ->toArray();

    return response($response->body(), $response->status(), $responseHeaders);
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC ROUTES — Auth Service (no JWT required)
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {

    // Registration
    Route::post('/auth/register', function (Request $request) {
        return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'), 'v1/auth/register');
    })->middleware('throttle:60,1')->name('gateway.auth.register');

    // Login
    Route::post('/auth/login', function (Request $request) {
        return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'), 'v1/auth/login');
    })->middleware('throttle:60,1')->name('gateway.auth.login');

    // Password recovery
    Route::post('/auth/forgot-password', function (Request $request) {
        return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'), 'v1/auth/forgot-password');
    })->middleware('throttle:30,1')->name('gateway.auth.forgot-password');

    Route::post('/auth/reset-password', function (Request $request) {
        return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'), 'v1/auth/reset-password');
    })->middleware('throttle:30,1')->name('gateway.auth.reset-password');

    // ── EXPLICITLY BLOCK INTERNAL ENDPOINTS (no auth needed, always 403) ──
    Route::any('/auth/validate', fn() => response()->json(['error' => 'Forbidden. Internal endpoint.'], 403))->name('gateway.auth.validate.block');
    Route::any('/users/{id}/claims', fn() => response()->json(['error' => 'Forbidden. Internal endpoint.'], 403))->where('id', '[0-9]+')->name('gateway.users.claims.block');

    // MFA Challenge — Step 2 of login (public: held challenge token, not JWT)
    Route::post('/auth/mfa/challenge', function (Request $request) {
        return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api'), 'v1/auth/mfa/challenge');
    })->middleware('throttle:10,1')->name('gateway.auth.mfa.challenge');

    // ─────────────────────────────────────────────────────────────────────────
    // PROTECTED ROUTES — JWT Required (Auth Gateway Middleware)
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware([\App\Http\Middleware\JwtAuthGateway::class])->group(function () {

        // ── AUTH SERVICE PROXY ──────────────────────────────────────────────
        Route::any('/auth/{any?}', function (Request $request, string $any = '') {
            // Block internal validate endpoint from client access
            if ($any === 'validate' || str_starts_with($any, 'validate')) {
                return response()->json(['error' => 'Forbidden. Internal endpoint.'], 403);
            }

            $baseUrl = env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/v1/auth';
            return proxyRequest($request, $baseUrl, $any);
        })->where('any', '.*')->name('gateway.auth.proxy');

        // ── USER SERVICE PROXY ──────────────────────────────────────────────
        Route::any('/users/{any?}', function (Request $request, string $any = '') {
            // Block internal claims endpoint from client access
            if (preg_match('/^\d+\/claims$/', $any)) {
                return response()->json(['error' => 'Forbidden. Internal endpoint.'], 403);
            }

            $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/v1/users';
            return proxyRequest($request, $baseUrl, $any);
        })->where('any', '.*')->name('gateway.users.proxy');

        // ── ROLE MANAGEMENT PROXY ───────────────────────────────────────────
        Route::any('/roles/{any?}', function (Request $request, string $any = '') {
            $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/v1/roles';
            return proxyRequest($request, $baseUrl, $any);
        })->where('any', '.*')->name('gateway.roles.proxy');

        // ── PERMISSION MANAGEMENT PROXY ─────────────────────────────────────
        Route::any('/permissions/{any?}', function (Request $request, string $any = '') {
            $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/v1/permissions';
            return proxyRequest($request, $baseUrl, $any);
        })->where('any', '.*')->name('gateway.permissions.proxy');

        // ── ORGANIZATION MANAGEMENT PROXY ───────────────────────────────────
        Route::any('/organizations/{any?}', function (Request $request, string $any = '') {
            $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/v1/organizations';
            return proxyRequest($request, $baseUrl, $any);
        })->where('any', '.*')->name('gateway.orgs.proxy');

    });

});
