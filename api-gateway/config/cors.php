<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration — Enterprise
|--------------------------------------------------------------------------
| Reads allowed origins from environment to support:
|   - Web clients (browser-based SPA)
|   - Mobile app clients (via proxy, typically no CORS needed but configured)
|   - Admin dashboards
|
| In production: set CORS_ALLOWED_ORIGINS to your specific frontend domains.
| Never use '*' with supports_credentials = true (browser security violation).
|--------------------------------------------------------------------------
*/

$allowedOrigins = array_filter(
    array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')))
);

return [
    // Apply CORS to all API routes
    'paths' => ['api/*', 'v1/*'],

    // Allowed HTTP methods
    'allowed_methods' => array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')))
    ),

    // Specific origins only — never '*' when credentials are used
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],

    // Headers clients can send
    'allowed_headers' => array_filter(
        array_map('trim', explode(',', env(
            'CORS_ALLOWED_HEADERS',
            'Content-Type,Authorization,X-Requested-With,X-Device-Fingerprint,X-App-Version,Accept'
        )))
    ),

    // Headers clients can read from the response
    'exposed_headers' => array_filter(
        array_map('trim', explode(',', env(
            'CORS_EXPOSE_HEADERS',
            'X-Request-Id,X-RateLimit-Limit,X-RateLimit-Remaining,X-RateLimit-Reset'
        )))
    ),

    // Preflight cache duration (24 hours)
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // Allow cookies/auth headers from specified origins
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', 'true'), FILTER_VALIDATE_BOOLEAN),
];
