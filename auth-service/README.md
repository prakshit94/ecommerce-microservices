# 🔐 Auth Service

The Auth Service manages user authentication, JWT token generation, and password synchronization in the eCommerce microservices architecture. It acts as the primary source of truth for user identification during login and registration.

---

## 🛠 Features

- **JWT Authentication**: Issue, refresh, and invalidate tokens using `tymon/jwt-auth`.
- **User Registration**: Create new accounts and sync details to the `user-service`.
- **Password Management**: Change passwords with current credential verification.
- **Role/Permission Extraction**: Fetches user claims from `user-service` and packs them into the JWT for efficient role checking at the Gateway.

---

## 📋 Prerequisites

- PHP 8.2+
- Composer
- SQLite (for local development)

---

## ⚙️ Installation & Setup

1. **Install Dependencies**:
   ```bash
   composer install
   ```

2. **Environment Configuration**:
   Configure the following in your `.env` file:
   ```ini
   APP_URL=http://localhost:8001/api/auth
   
   # Shared Secrets
   JWT_SECRET=your_jwt_secret_here
   GATEWAY_SECRET=your_strong_gateway_secret_here
   
   # User Service Configuration
   USER_SERVICE_URL=http://localhost:8002/api
   ```

3. **Database Setup**:
   ```bash
   php artisan migrate
   ```

4. **Start the Service**:
   ```bash
   php artisan serve --port=8001
   ```

---

## 🔌 API Endpoints

### 📝 User Registration
**Endpoint**: `POST /api/auth/register`
Creates a user in the local database and synchronizes the record to the `user-service`.

- **Body**:
  ```json
  {
    "name": "Test User",
    "email": "test@example.com",
    "password": "password123"
  }
  ```

### 🔑 User Login
**Endpoint**: `POST /api/auth/login`
Generates a JWT token containing user ID, email, roles, and permissions.

- **Body**:
  ```json
  {
    "email": "test@example.com",
    "password": "password123"
  }
  ```
- **Response**:
  ```json
  {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
  ```

### 👤 Current User (Me)
**Endpoint**: `GET /api/auth/me`
Returns the authenticated user's profile details. Requires a valid JWT.

### 🔄 Token Refresh
**Endpoint**: `POST /api/auth/refresh`
Generates a new JWT token using an existing, valid token.

### 🚪 Logout
**Endpoint**: `POST /api/auth/logout`
Invalidates the current JWT token, effectively logging out the user.

### 🛡 Change Password
**Endpoint**: `POST /api/auth/change-password`
Updates the user's password globally across services.

- **Body**:
  ```json
  {
    "current_password": "old_password_here",
    "new_password": "new_password_123",
    "new_password_confirmation": "new_password_123"
  }
  ```

---

## 🔄 Service Synchronization

### User Sync on Registration
On a successful registration, the Auth Service makes an internal `POST` request to `USER_SERVICE_URL/users` with the user ID and details. This ensures both services stay in sync for profile and RBAC management.

### User Sync on Password Change
When a password is changed, the Auth Service performs an internal `PUT` request to `USER_SERVICE_URL/users/{id}` to ensure the `user-service` is updated with the new encrypted password hash.

> [!IMPORTANT]
> All inter-service communication requires the `X-Gateway-Secret` header for security.
