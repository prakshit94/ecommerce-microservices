# 👤 User Service (Profiles & RBAC)

The User Service is the **Source of Truth** for user profiles and access levels. It manages the Role-Based Access Control (RBAC) hierarchy and provides security auditing for identity changes.

## 🛠 Features

- **RBAC Engine**: Manages Roles and Permissions using the Spatie Permission system.
- **Custom Audit Logging**: Automatically tracks every change (Create, Update, Delete) to the User model.
- **Internal Claims Provider**: Serves the Auth Service with granular roles/permissions for JWT payload creation.
- **Identity Hardening**: Enforces strict permission checks on all role-assignment operations.

---

## 📋 Environment Configuration

| Variable | Description | Default |
| :--- | :--- | :--- |
| `GATEWAY_SECRET` | Required for all incoming requests (internal or proxied). | Required |
| `DB_CONNECTION` | Database driver. | `sqlite` |

---

## 🎭 RBAC Permissions Catalog

The system supports fine-grained control across three domains:

| Domain | Permissions |
| :--- | :--- |
| **Users** | `user-list`, `user-view`, `user-create`, `user-update`, `user-delete` |
| **Roles** | `role-list`, `role-view`, `role-create`, `role-update`, `role-delete`, `role-assign` |
| **Permissions**| `permission-list`, `permission-view`, `permission-create`, `permission-manage` |

---

## 🔌 API Endpoints (User Service)

All endpoints below require **Both** `X-Gateway-Secret` and suitable permissions in the `X-User-Permissions` header (verified by `CheckPermission` middleware).

### 👥 User Management
| Method | Endpoint | Permission Required | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/users` | `user-list` | List all user profiles. |
| `POST` | `/users` | `user-create` | Create a new user account. |
| `GET` | `/users/{id}` | `user-view` | View user profile + audit history. |
| `PUT` | `/users/{id}` | `user-update` | Update user info (Role-protected). |

### 🎭 Role & Permission Management
| Method | Endpoint | Permission Required | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/roles` | `role-create` | Create a new security role. |
| `POST` | `/permissions` | `permission-create` | Create a new permission. |
| `POST` | `/users/{id}/roles`| `role-assign` | Assign roles to a specific user. |

---

## 🛡 Security Logic: Custom Auditing

The User Service implements a **Native Audit Broker** that doesn't rely on external packages, ensuring 100% PHP 8.2+ compatibility.

### How it works:
1. The `User` model uses a `booted()` hook to listen for Eloquent events.
2. For every `created`, `updated`, or `deleted` event, it calls `logAction()`.
3. It captures:
   - **Old vs New Values**: Only modified fields are logged for updates.
   - **Context**: The `X-User-Id` (forwarded by Gateway) who performed the action.
   - **Metadata**: IP address and User-Agent from the request.

---

## 📦 Usage Examples

### Assigning a Role to a User
```bash
# Proxied via Gateway
curl -X POST http://127.0.0.1:8000/api/users/5/roles \
     -H "Authorization: Bearer <ADMIN_TOKEN>" \
     -H "Content-Type: application/json" \
     -d '{"roles": ["Admin", "Moderator"]}'
```

### Viewing a User (Includes Audit History)
```bash
curl -X GET http://127.0.0.1:8000/api/users/5 \
     -H "Authorization: Bearer <TOKEN>"
```
