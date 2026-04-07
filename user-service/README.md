# 👤 User Service

The User Service manages user profiles, roles, and permissions in the eCommerce microservices architecture. It acts as the source of truth for Role-Based Access Control (RBAC) and provides claims to the Auth Service during JWT generation.

---

## 🛠 Features

- **Profile Management**: CRUD operations for user account information.
- **RBAC (Spatie)**: Full Role and Permission management using `spatie/laravel-permission`.
- **Claims Provider**: Serves internal requests from Auth Service to extract user roles/permissions as JWT claims.
- **Internal Security**: Validates the `X-Gateway-Secret` for all inter-service requests.

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
   APP_URL=http://localhost:8002/api
   
   # Gateway Security Secret
   GATEWAY_SECRET=your_strong_gateway_secret_here
   ```

3. **Database Setup**:
   ```bash
   php artisan migrate
   ```

4. **Start the Service**:
   ```bash
   php artisan serve --port=8002
   ```

---

## 🔌 API Endpoints

### 👤 User Profile Management
**Endpoint**: `GET|POST|PUT|DELETE /api/users/{id?}`
Standard CRUD for managing users. Integrated with Auth Service during registration.

### 🎭 Role Management
**Endpoint**: `GET|POST|PUT|DELETE /api/roles/{id?}`
CRUD for administrative roles (e.g., `admin`, `customer`, `manager`).

- **Create Role**: `POST /api/roles`
  ```json
  { "name": "editor" }
  ```

- **Assign Permissions to Role**: `POST /api/roles/{id}/permissions`
  ```json
  { "permissions": ["edit-products", "view-orders"] }
  ```

### 🔐 Permission Management
**Endpoint**: `GET|POST|PUT|DELETE /api/permissions/{id?}`
CRUD for individual system permissions.

---

### 🛡 User RBAC Assignment

#### 1. Assign Roles to User
**Endpoint**: `POST /api/users/{userId}/roles`
Synchronizes specific roles to a user account.

- **Body**:
  ```json
  { "roles": ["admin", "customer"] }
  ```

#### 2. Assign Direct Permissions to User
**Endpoint**: `POST /api/users/{userId}/permissions`
Synchronizes specific direct permissions to a user account.

- **Body**:
  ```json
  { "permissions": ["refund-orders"] }
  ```

---

## 🔄 Internal Communication

### 🔑 Claims Extraction
**Endpoint**: `GET /api/users/{id}/claims`
This is an internal route used by the **Auth Service** during login to fetch the user's roles and permissions for inclusion in the JWT token.

- **Returns**:
  ```json
  {
    "roles": ["admin"],
    "permissions": ["edit-products", "view-orders"]
  }
  ```

> [!IMPORTANT]
> All users and roles must be created within this service to maintain integrity. The `auth-service` manages password logic, but the `user-service` manages the actual account identity and access levels.
