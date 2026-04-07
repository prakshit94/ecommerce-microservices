# 🚀 API Gateway Service

The API Gateway is the single entry point for all client requests in the eCommerce microservices architecture. It handles authentication, request routing, and header forwarding to downstream services.

---

## 🛠 Features

- **Centralized Authentication**: Verifies JWT tokens and extracts user claims.
- **Request Proxying**: Routes requests to `auth-service` and `user-service`.
- **Security**: Strips client-side `X-User-*` headers to prevent spoofing and injects verified user data.
- **Granular RBAC**: Supports 18+ specific permissions (e.g., `user-create`, `role-delete`).
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
| `POST` | `/api/auth/forgot-password` | Request password reset | No |
| `POST` | `/api/auth/reset-password` | Reset password with token | No |

### 👤 User & RBAC Proxy (`/api/users/*`, `/api/roles/*`, `/api/permissions/*`)
Proxies to `user-service` at `PORT 8002`.

| Method | Endpoint | Description | Permission Required |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/users` | List all users | `user-list` |
| `POST` | `/api/users` | Create new user | `user-create` |
| `GET` | `/api/users/{id}` | Get user details | `user-view` |
| `PUT` | `/api/users/{id}` | Update user info | `user-update` |
| `DELETE` | `/api/users/{id}` | Delete user | `user-delete` |
| `GET` | `/api/roles` | List all roles | `role-list` |
| `POST` | `/api/roles` | Create new role | `role-create` |
| `GET` | `/api/roles/{id}` | Get role details | `role-view` |
| `PUT` | `/api/roles/{id}` | Update role name | `role-update` |
| `DELETE` | `/api/roles/{id}` | Delete role | `role-delete` |
| `POST` | `/api/roles/{id}/permissions` | Assign perms to role | `role-manage-permissions` |

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
