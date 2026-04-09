<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

function proxyRequest(Request $request, string $baseUrl, string $path): Response {
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    
    // Forward all headers except Host to avoid issues
    $headers = collect($request->header())->mapWithKeys(function ($values, $key) {
        return [$key => $values[0]];
    })->except(['host', 'x-gateway-secret', 'content-length', 'content-type'])->toArray();

    $headers['X-Gateway-Secret'] = env('GATEWAY_SECRET');
    $headers['X-Forwarded-For'] = $request->ip();
    $headers['X-Real-IP'] = $request->ip();
    $headers['User-Agent'] = $request->userAgent();

    $client = Http::withoutVerifying()->withHeaders($headers);
    
    if (str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
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
        if ($request->header('Content-Type')) {
            $headers['Content-Type'] = $request->header('Content-Type');
            $client = Http::withoutVerifying()->withHeaders($headers);
        }
        $response = $client->send($request->method(), $url, [
            'body' => $request->getContent()
        ]);
    }

    $responseHeaders = collect($response->headers())->except(['Transfer-Encoding', 'transfer-encoding'])->toArray();
    return response($response->body(), $response->status(), $responseHeaders);
}

// 🔓 Public Routes Proxy (Auth Service - Restricted)
Route::post('/auth/login', function (Request $request) {
    return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth', 'login');
});

Route::post('/auth/forgot-password', function (Request $request) {
    return proxyRequest($request, env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth', 'forgot-password');
});


// 🔐 Protected Routes Proxy (Enforced by Gateway)
Route::middleware([\App\Http\Middleware\JwtAuthGateway::class])->group(function () {
    
    // Protected Auth Proxy (register, reset-password, me, logout, sessions, history)
    Route::any('/auth/{any?}', function (Request $request, $any = '') {
        $baseUrl = env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth';
        return proxyRequest($request, $baseUrl, $any);
    })->where('any', '.*');

    Route::any('/users/{any?}', function (Request $request, $any = '') {
        // 🚨 Block data leakage
        if (preg_match('/^\d+\/claims$/', $any)) {
            return response()->json(['error' => 'Forbidden. Internal Endpoint.'], 403);
        }
        
        $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/users';
        return proxyRequest($request, $baseUrl, $any);
    })->where('any', '.*');

    Route::any('/roles/{any?}', function (Request $request, $any = '') {
        $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/roles';
        return proxyRequest($request, $baseUrl, $any);
    })->where('any', '.*');

    Route::any('/permissions/{any?}', function (Request $request, $any = '') {
        $baseUrl = env('USER_SERVICE_URL', 'http://127.0.0.1:8002/api') . '/permissions';
        return proxyRequest($request, $baseUrl, $any);
    })->where('any', '.*');

});
