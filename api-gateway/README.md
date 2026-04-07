# 🚀 API Gateway Service

The API Gateway is the single entry point for all client requests in the eCommerce microservices architecture. It handles authentication, request routing, and header forwarding to downstream services.

---

## 🛠 Features

- **Centralized Authentication**: Verifies JWT tokens and extracts user claims.
- **Request Proxying**: Routes requests to `auth-service` and `user-service`.
- **Security**: Strips client-side `X-User-*` headers to prevent spoofing and injects verified user data.
- **Consolidated API**: Provides a unified interface for the frontend.

---

## 📋 Prerequisites

- PHP 8.2+
- Composer
- SQLite (for local development)

---

## ⚙️ Installation & Setup

1. **Clone and Install**:
   ```bash
   composer install
   ```

2. **Environment Configuration**:
   Create a `.env` file and configure the following critical variables:
   ```ini
   APP_URL=http://localhost:8000
   
   # JWT and Gateway Security
   JWT_SECRET=your_jwt_secret_here
   GATEWAY_SECRET=your_strong_gateway_secret_here
   
   # Downstream Service URLs
   AUTH_SERVICE_URL=http://localhost:8001/api
   USER_SERVICE_URL=http://localhost:8002/api
   ```

3. **Start the Service**:
   ```bash
   php artisan serve --port=8000
   ```

---

## 🔌 API Routes & Proxying

The Gateway proxies requests based on the URL prefix.

### 🔐 Authentication Proxy (`/api/auth/*`)
Proxies to `auth-service` at `PORT 8001`.

| Method | Endpoint | Description | Auth Required |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/auth/register` | Register new user | No |
| `POST` | `/api/auth/login` | Login and get JWT | No |
| `POST` | `/api/auth/logout` | Logout user | Yes |
| `POST` | `/api/auth/refresh` | Refresh JWT token | Yes |
| `GET` | `/api/auth/me` | Get current user info | Yes |
| `POST` | `/api/auth/change-password` | Change user password | Yes |

### 👤 User & RBAC Proxy (`/api/users/*`, `/api/roles/*`, `/api/permissions/*`)
Proxies to `user-service` at `PORT 8002`.

| Method | Endpoint | Description | Auth Required |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/users` | List all users | Yes |
| `GET` | `/api/users/{id}` | Get user details | Yes |
| `PUT` | `/api/users/{id}` | Update user info | Yes |
| `DELETE` | `/api/users/{id}` | Delete user | Yes |
| `GET` | `/api/roles` | List all roles | Yes |
| `POST` | `/api/roles` | Create new role | Yes |
| `GET` | `/api/permissions` | List all permissions | Yes |
| ... | ... | ... | ... |

---

## 🛡 Security Mechanism

### JWT Verification
The Gateway intercepts requests to protected routes (via `JwtAuthGateway` middleware). It validates the token and extracts the following claims:
- `user_id`
- `email`
- `roles`
- `permissions`

### Header Forwarding
Once verified, the Gateway forwards the request to downstream services with the following headers:
- `X-User-Id`: The unique ID of the authenticated user.
- `X-User-Email`: The user's email.
- `X-User-Roles`: JSON encoded list of roles.
- `X-User-Permissions`: JSON encoded list of direct permissions.
- `X-Gateway-Secret`: A shared secret to verify the request originated from the Gateway.

> [!CAUTION]
> Downstream services **must** verify the `X-Gateway-Secret` to ensure the security of relayed user information.
