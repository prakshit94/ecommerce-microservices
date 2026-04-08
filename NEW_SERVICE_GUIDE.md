# 🛠 New Service Implementation Guide (Step-by-Step)

This guide provides a comprehensive checklist and instructions for adding a new microservice (e.g., `order-service`, `inventory-service`) to the existing eCommerce ecosystem.

---

## 🏗 Phase 1: Scaffolding

### 1. Create the Laravel Project
Run this command from the root directory of the ecosystem:
```bash
composer create-project laravel/laravel:^12.0 your-service-name
```

### 2. Configure for API-Only
In a microservices architecture, you typically don't need sessions (except in Auth), CSRF, or Cookies.
In `bootstrap/app.php`, ensure it is optimized for API:
```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
```

---

## 🔐 Phase 2: Security Integration

### 1. Environment Configuration
Copy the shared secrets from your `api-gateway/.env` to your `your-service-name/.env`:
```ini
GATEWAY_SECRET="the_same_secret_as_gateway"
JWT_SECRET="the_same_secret_as_gateway"

# Ensure SQLite is correctly path-referenced
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

### 2. Implement the `RequireGatewaySecret` Middleware
Create `app/Http/Middleware/RequireGatewaySecret.php`:
```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireGatewaySecret {
    public function handle(Request $request, Closure $next) {
        $secret = env('GATEWAY_SECRET');
        if (!$secret || $request->header('X-Gateway-Secret') !== $secret) {
            return response()->json(['error' => 'Forbidden. Invalid Gateway Secret.'], 403);
        }
        return $next($request);
    }
}
```

### 3. Register the Middleware
In `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'gateway.secret' => \App\Http\Middleware\RequireGatewaySecret::class,
    ]);
})
```

---

## 🔌 Phase 3: Gateway Registration

### 1. Add Service URL to Gateway
Update `api-gateway/.env`:
```ini
YOUR_SERVICE_URL=http://127.0.0.1:8003/api
```

### 2. Create Proxy Route
Update `api-gateway/routes/api.php` inside the `JwtAuthGateway::class` middleware group:
```php
Route::any('/your-service/{any?}', function (Request $request, $any = '') {
    $baseUrl = env('YOUR_SERVICE_URL', 'http://127.0.0.1:8003/api') . '/your-service';
    return proxyRequest($request, $baseUrl, $any);
})->where('any', '.*');
```

---

## 👤 Phase 4: Consuming Identity

Inside your new service, you do NOT need to decode the JWT again. The Gateway has already done this for you. Use the injected headers:

```php
// In any Controller or Middleware
$userId = $request->header('X-User-Id');
$permissions = json_decode($request->header('X-User-Permissions'), true);

if (!in_array('order-create', $permissions)) {
    return response()->json(['error' => 'Unauthorized'], 403);
}
```

---

## 📋 Final Checklist

- [ ] Does your service use the same `GATEWAY_SECRET` as the others?
- [ ] Is the database initialized (`php artisan migrate`)?
- [ ] Did you register the service's port (e.g., 8003) in the Gateway's `.env`?
- [ ] Have you tested a request through `localhost:8000/api/your-service`?
- [ ] If you have public endpoints, are they outside the Gateway's `JwtAuthGateway` group?

---

> [!TIP]
> **Port Allocation Strategy**
> - 8000: Gateway
> - 8001: Auth
> - 8002: User
> - 8003: Products
> - 8004: Orders
> - 8005: Payments
