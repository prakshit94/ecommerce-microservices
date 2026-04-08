# 🔐 Auth Service (Identity & Sessions)

The Auth Service is the **Identity Provider (IdP)** for the ecosystem. It manages user credentials, JWT lifecycle, and active session monitoring.

## 🛠 Features

- **JWT Lifecycle**: Issues secure JWTs with encoded roles and permissions.
- **Session Tracking**: Stores every active login in the `user_sessions` table for monitoring and revocation.
- **Instant Revocation**: Supports global logout and specific session termination.
- **Login History**: Logs every login event (IP, User-Agent, Status).
- **Password Handshake**: Synchronizes password hashes with `user-service` via secure internal APIs.

---

## 📋 Environment Configuration

| Variable | Description | Default |
| :--- | :--- | :--- |
| `JWT_SECRET` | Secret key for signing and verifying tokens. | Required |
| `GATEWAY_SECRET` | Verified for incoming requests from the Gateway. | Required |
| `USER_SERVICE_URL` | Used to fetch claims and sync passwords. | `http://127.0.0.1:8002/api` |

---

## 🔌 API Endpoints (Auth Service)

All endpoints below require the `X-Gateway-Secret` header to be present.

### 🔓 Public Endpoints
| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/auth/register` | Create user and sync with User Service. |
| `POST` | `/auth/login` | Authenticate and create a session. |
| `POST` | `/auth/validate` | **Internal**: Gateway calls this to check JTI validation. |

### 🔐 Protected Endpoints (JWT Required)
| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/auth/sessions` | List all active sessions for the user. |
| `DELETE`| `/auth/sessions/{id}` | Revoke a specific session (by ID). |
| `GET` | `/auth/history` | View the user login history log. |
| `POST` | `/auth/change-password` | Update password globally and revoke all sessions. |
| `POST` | `/auth/logout` | Revoke the current session. |

---

## 🛡 Security Logic: Session Management

### 1. Token Creation
During login, a new `jti` (unique JWT ID) is generated and stored in the `user_sessions` table along with the user's IP address and user agent.

### 2. Validation Handshake
When the Gateway forwards a `jti`, this service checks if:
- The session exists.
- The session is not expired.
- The session has not been manually revoked.

### 3. Global Logout (On Password Change)
When a user changes their password, **all** active records in `user_sessions` for that user are deleted, immediately invalidating all tokens in the wild.

---

## 📦 Usage Examples

### Fetching Active Sessions
```bash
# Handled via Gateway
curl -X GET http://127.0.0.1:8000/api/auth/sessions \
     -H "Authorization: Bearer <TOKEN>"
```

### Revoking a Session
```bash
# Deletes session ID 42 from the database
curl -X DELETE http://127.0.0.1:8000/api/auth/sessions/42 \
     -H "Authorization: Bearer <TOKEN>"
```
