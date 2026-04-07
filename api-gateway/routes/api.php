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
    })->except(['host', 'x-gateway-secret'])->toArray();

    $headers['X-Gateway-Secret'] = env('GATEWAY_SECRET');

    $response = Http::withoutVerifying()
        ->withHeaders($headers)
        ->send($request->method(), $url, [
            'body' => $request->getContent()
        ]);

    return response($response->body(), $response->status(), $response->headers());
}

// 🔓 Public Routes Proxy (Auth Service)
Route::any('/auth/{any?}', function (Request $request, $any = '') {
    $baseUrl = env('AUTH_SERVICE_URL', 'http://127.0.0.1:8001/api') . '/auth';
    return proxyRequest($request, $baseUrl, $any);
})->where('any', '.*');


// 🔐 Protected Routes Proxy (User Service)
Route::middleware([\App\Http\Middleware\JwtAuthGateway::class])->group(function () {
    
    Route::any('/users/{any?}', function (Request $request, $any = '') {
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
