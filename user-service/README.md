# 👤 User Service

The User Service manages user profiles, roles, and permissions in the eCommerce microservices architecture. It acts as the source of truth for Role-Based Access Control (RBAC) and provides claims to the Auth Service during JWT generation.

---

## 🛠 Features

- **Profile Management**: CRUD operations for user account information.
- **Granular RBAC (Spatie)**: 18+ specific permissions for fine-grained access control.
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
   php artisan migrate --seed
   ```

4. **Start the Service**:
   ```bash
   php artisan serve --port=8002
   ```

---

## 🛡️ Granular Permissions

| Category | Permissions |
| :--- | :--- |
| **Users** | `user-list`, `user-view`, `user-create`, `user-update`, `user-delete` |
| **Roles** | `role-list`, `role-view`, `role-create`, `role-update`, `role-delete`, `role-assign`, `role-manage-permissions` |
| **Permissions** | `permission-list`, `permission-view`, `permission-create`, `permission-update`, `permission-delete`, `permission-assign` |

---

## 🔌 API Endpoints (Proxied by Gateway)

### 👤 User Profile Management
**Endpoint**: `GET|POST|PUT|DELETE /api/users/{id?}`

- **List Users**: `GET /api/users` (Perm: `user-list`)
- **Create User**: `POST /api/users` (Perm: `user-create`)
- **Update User**: `PUT /api/users/{id}` (Perm: `user-update`)
- **Delete User**: `DELETE /api/users/{id}` (Perm: `user-delete`)

### 🎭 Role Management
**Endpoint**: `GET|POST|PUT|DELETE /api/roles/{id?}`

- **Create Role**: `POST /api/roles` (Perm: `role-create`)
- **Assign Perms to Role**: `POST /api/roles/{id}/permissions` (Perm: `role-manage-permissions`)

### 🔑 Permission Management
**Endpoint**: `GET|POST|PUT|DELETE /api/permissions/{id?}`

- **Create Permission**: `POST /api/permissions` (Perm: `permission-create`)

---

### 🛡️ User RBAC Assignment

#### 1. Assign Roles to User
**Endpoint**: `POST /api/users/{userId}/roles` (Perm: `role-assign`)
- **Body**: `{ "roles": ["Admin", "Manager"] }`

#### 2. Assign Direct Permissions to User
**Endpoint**: `POST /api/users/{userId}/permissions` (Perm: `permission-assign`)
- **Body**: `{ "permissions": ["user-list", "user-view"] }`

---

## 🔄 Internal Communication

### 🔑 Claims Extraction
**Endpoint**: `GET /api/users/{id}/claims` (Internal)
This is an internal route used by the **Auth Service** during login to fetch the user's roles and permissions for inclusion in the JWT token.

- **Returns**:
  ```json
  {
    "roles": ["Admin"],
    "permissions": ["user-list", "user-view", "role-list"]
  }
  ```

> [!IMPORTANT]
> All users and roles must be created within this service to maintain integrity. The `auth-service` manages password logic, but the `user-service` manages the actual account identity and access levels.
