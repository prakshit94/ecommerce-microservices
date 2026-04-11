# 🛠️ New Microservice Creation Guide

This guide provides a step-by-step checklist and architectural rules for creating a **new microservice** (e.g., `product-service`, `inventory-service`) that seamlessly plugs into the existing Enterprise Gateway ecosystem.

---

## 1. Directory & Initialization
All microservices must live in the root directory.

1. **Scaffold a fresh Laravel service**
   ```bash
   composer create-project laravel/laravel new-service-name
   ```
2. **Assign a Port**
   - API Gateway: `8000`
   - Auth Service: `8001`
   - User Service: `8002`
   - *New Service*: Pick the next sequential port (e.g., `8003` for `product-service`).

## 2. Environment Configurations (`.env`)
Every microservice must adhere to strict ecosystem configuration patterns to communicate securely.

**Required `.env` Variables for a new service:**
```env
# Sync the App Key if cookies/encryption need to be shared
APP_NAME="New Service"
APP_ENV=local
APP_KEY=YOUR_GENERATED_KEY
APP_DEBUG=true
APP_URL=http://localhost:8003

# Must match the Auth, User, and Gateway exactly!
GATEWAY_SECRET=YOUR_64_CHAR_GATEWAY_SECRET

# Inter-Service URLs (for when the new service needs to talk out)
GATEWAY_URL=http://127.0.0.1:8000/api
AUTH_SERVICE_URL=http://127.0.0.1:8001/api
USER_SERVICE_URL=http://127.0.0.1:8002/api

# Shared Database/Cache
DB_CONNECTION=mysql # or whatever your standard is
CACHE_STORE=database # or redis if running centrally
```

## 3. The Gateway Middleware Rule
A backend microservice **must NEVER** be directly accessible by the outside world. It should only accept traffic that has been routed and verified by the API Gateway.

1. Create a middleware named `RequireGatewaySecret`:
   ```bash
   php artisan make:middleware RequireGatewaySecret
   ```
2. Implement strict header checking:
   ```php
   public function handle(Request $request, Closure $next)
   {
       $secret = config('app.gateway_secret', env('GATEWAY_SECRET'));
       $header = $request->header('X-Gateway-Secret');

       if (!$header || !hash_equals($secret, $header)) {
           return response()->json([
               'error' => 'Forbidden. Origin could not be verified.',
               'code'  => 'INVALID_GATEWAY_SECRET'
           ], 403);
       }

       return $next($request);
   }
   ```
3. Apply this middleware to **ALL** routes in `routes/api.php` so the entire service is locked behind the `GATEWAY_SECRET`.

## 4. Hooking the New Service into the API Gateway
Once your service is running and secured, it must be mapped to the `api-gateway`.

In `api-gateway/routes/api.php`, add a new proxy block inside the `JwtAuthGateway::class` (or outside if the routes are public):

```php
// ── NEW SERVICE PROXY ──────────────────────────────────────────────
Route::any('/new-endpoint/{any?}', function (Request $request, string $any = '') {
    $baseUrl = env('NEW_SERVICE_URL', 'http://127.0.0.1:8003/api') . '/v1/new-endpoint';
    return proxyRequest($request, $baseUrl, $any);
})->where('any', '.*')->name('gateway.new.proxy');
```
*Note: Make sure to add `NEW_SERVICE_URL` to the API Gateway's `.env`!*

## 5. Trusting the Logged-In User (`X-User-Id`)
Because the Gateway strips out JWT tokens and handles authentication upfront, your new microservice should **not** validate JWTs directly. Instead, look for the injected headers provided by the Gateway.

Whenever a user makes a request to your service, the API Gateway will inject:
* `X-User-Id`: The user's database ID
* `X-User-Roles`: A comma-separated list of their Spatie roles
* `X-User-Permissions`: A comma-separated list of their explicitly granted permissions

**Example Controller Logic:**
```php
public function store(Request $request) 
{
    $userId = $request->header('X-User-Id');
    if (!$userId) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }
    
    // Check roles natively!
    $roles = explode(',', $request->header('X-User-Roles', ''));
    if (!in_array('developer', $roles)) {
        return response()->json(['error' => 'Forbidden'], 403);
    }
    
    // Proceed with business logic...
}
```

## 6. Service-to-Service Communication Bypasses
Sometimes, your new service needs to talk directly to the `user-service` behind the scenes without a real "User" initiating the request (e.g., syncing records). 

To do this, use the internal sync bypass header:
```php
use Illuminate\Support\Facades\Http;

$response = Http::withHeaders([
    'X-Gateway-Secret' => env('GATEWAY_SECRET'),
    'X-Internal-Sync'  => 'true', // Bypasses the strict X-User-Id requirement
    'Accept'           => 'application/json'
])->post(env('USER_SERVICE_URL') . '/v1/some-internal-task', [
    'data' => 'payload'
]);
```

## 7. JSON Response Standardization
Every API returned from any microservice must be properly wrapped in API Resources instead of raw models to ensure your frontend teams have a consistent experience.

**BAD Output (Raw object):**
```json
{
    "id": 1,
    "name": "Product Name"
}
```

**GOOD Output (Array wrapped in `data`):**
```json
{
    "data": {
        "id": 1,
        "name": "Product Name"
    }
}
```
*Always enforce this by running `php artisan make:resource MyResource` and returning `return new MyResource($model);` in your controllers.*

---

## ✅ Deployment Checklist for the New Service

1. [ ] Sequential Application Port assigned mapping to the Docker or PM2 config.
2. [ ] `GATEWAY_SECRET` configured in `.env` matching the core services.
3. [ ] `RequireGatewaySecret` Middleware applied to the `routes/api.php` root group.
4. [ ] Service entry added to the API Gateway's proxy configurations.
5. [ ] Controllers modified to read `X-User-Id` instead of normal Laravel Auth mechanisms.
6. [ ] Resource wrappers built out for consistent `data` key JSON outputs.
