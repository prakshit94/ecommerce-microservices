# 🚀 API Gateway Service (Hardened)

The API Gateway is the **security perimeter** for the ecosystem. It manages all ingress traffic, performs high-speed session validation, and enforces strict inter-service authentication.

## 🛠 Core Responsibilities

- **Centralized Authentication**: Verifies JWT signatures and extracts claims.
- **Session Revocation Handshake**: Handshakes with `auth-service` to check if a token's `jti` is revoked.
- **Header Injection**: Verified user metadata is injected into `X-User-Id`, `X-User-Roles`, and `X-User-Permissions`.
- **Inter-Service Security**: Injects `X-Gateway-Secret` for downstream service verification.
- **Data Leakage Protection**: Explicitly blocks access to internal endpoints (like `/claims`).
- **Multipart Support**: Transparently proxies `multipart/form-data` for file uploads.

---

## 📋 Environment Configuration

| Variable | Description | Default |
| :--- | :--- | :--- |
| `GATEWAY_SECRET` | Shared secret for inter-service validation. | Required |
| `JWT_SECRET` | Shared secret for signing tokens. | Required |
| `AUTH_SERVICE_URL` | URL of the Auth Microservice. | `http://127.0.0.1:8001/api` |
| `USER_SERVICE_URL` | URL of the User Microservice. | `http://127.0.0.1:8002/api` |

---

## 🔌 API Endpoints & Proxying

### 🔓 Public Proxy (`/api/auth/*`)
Routes to the Auth Service. These endpoints do not require a JWT.

| Method | Path | Target |
| :--- | :--- | :--- |
| `POST` | `/auth/register` | `auth-service/register` |
| `POST` | `/auth/login` | `auth-service/login` |
| `POST` | `/auth/forgot-password`| `auth-service/forgot-password`|

### 🔐 Protected Proxy
All endpoints below require a valid **Bearer Token** and undergo a **JTI Revocation Check**.

| Target Service | Path Prefix | Headers Injected |
| :--- | :--- | :--- |
| **Auth Service** | `/api/auth/me`, `/api/auth/sessions` | `X-User-Id`, `X-Gateway-Secret` |
| **User Service** | `/api/users/*` | `X-User-Id`, `X-User-Permissions`, ... |
| **User Service** | `/api/roles/*` | `X-User-Id`, `X-User-Permissions`, ... |

---

## 🛡 Security Logic: `JwtAuthGateway`

The `JwtAuthGateway` middleware performs the following critical steps for every protected request:

1. **Header Stripping**: Removes any client-side `X-User-*` headers to prevent spoofing.
2. **JWT Decode**: Decodes the token using the shared `JWT_SECRET`.
3. **Session Handshake**: 
   ```php
   // API Gateway -> Auth Service handshake
   $response = Http::post('auth-service/auth/validate', ['jti' => $token_jti]);
   if (!$response->json('valid')) return 401;
   ```
4. **Header Injection**: Populates verified claims into headers for the target microservice.

---

## 📦 How to Use (Proxy Examples)

### Standard JSON Proxy
The gateway preserves the original JSON body and forwards it to the target service.
```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@example.com", "password":"admin123"}'
```

### Multipart File Upload Proxy
The gateway detects `multipart/form-data`, rebuilds the request using Laravel's `attach()` method, and proxies it to the downstream service.
```bash
curl -X POST http://127.0.0.1:8000/api/users/profile-image \
     -H "Authorization: Bearer <TOKEN>" \
     -F "avatar=@image.png"
```
