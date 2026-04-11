# Enterprise E-Commerce Microservices Architecture

This project is a highly scalable, production-ready, Service-Oriented Architecture (SOA) consisting of automated Gateway routing, robust Authentication, and comprehensive User/Role Management.

## 🚀 Architecture Overview

The backend is composed of several independent Laravel-based microservices, loosely coupled and highly secure:

1. **API Gateway (`port 8000`)**: The single entry point for all client applications. It proxies requests to the appropriate backend service, enforces strict global rate limiting (e.g. max 5 login attempts per minute), mitigates brute force attacks, and entirely blocks unauthorized internal endpoints.
2. **Auth Service (`port 8001`)**: A stateless authentication provider handling JWT issuance, session revocations, strict device tracking, and Multi-Factor Authentication (MFA via Google Authenticator TOTP).
3. **User Service (`port 8002`)**: Handles Identity and Access Management (IAM). This service manages User profiles, strict Hierarchical Role-Based Access Control (RBAC with Spatie), Organizations (Multi-Tenancy), and Teams.

### Internal Communication
Services communicate securely via internal API requests. To prevent a malicious attacker from bypassing the gateway to hit an internal microservice, all inter-service communications enforce a strict `X-Gateway-Secret` header validation middleware. 

---

## 🛠️ Quick Start Guide

### 1. Requirements
* **PHP 8.2+**
* **Composer**
* **Redis** (optional but highly recommended for token caching & rate-limiting)
* **MySQL / PostgreSQL**
* **Mailpit / Mailtrap** (for local SMTP testing like forgot password)

### 2. Startup Scripts
A comprehensive startup script handles running migrations across all databases, linking storage, and booting up the local PHP development servers.

From the root directory, simply run:
```powershell
.\start_auth_ecosystem.ps1
```
*(This launches the Gateway, Auth, and User services on ports 8000, 8001, and 8002 respectively).*

### 3. Using Postman
An extensively automated Postman collection is included: `ecommerce_microservices_postman_collection.json`. 
1. Import this file into Postman.
2. Run the **Login** request found in the "Public Auth" folder.
3. Postman will automatically capture your `{{token}}` and apply it to all other protected requests!

---

## 🔐 Core Features & Concepts

### Rate Limiting & Brute Force Prevention
Login attempts are strictly bottlenecked to protect user accounts. If a user tries to brute-force a login, the gateway will block the IP globally with a `429 Too Many Requests`.

### Device Tracking & Session Management
Every time a user logs in, the Auth Service stores their IP and User-Agent. Users can query their active sessions and securely revoke unknown sessions from other devices automatically. 

### Multi-Factor Authentication (MFA)
You can enable robust TOTP functionality on any account via the `/mfa/setup` endpoint. If enabled, the standard Login endpoint will return a temporary `mfa_token` and an `mfa_required` flag instead of a standard JWT. The user must then complete step two by submitting the 6-digit authenticator code via `/mfa/challenge`.

### Multi-Tenancy (Organizations & Teams)
Users belong to global Roles (e.g., system admin), but the system is fundamentally multi-tenant. You can segregate access by dynamically assigning Users to specific Organizations and Teams. 

---

## 🗺️ API Reference
*All external API requests must be routed through the API Gateway at `http://localhost:8000/api`*

### System Health
| Method | Endpoint | Description |
|--------|---------|-------------|
| GET | `/health` | Basic UP status |
| GET | `/health/ready` | Deep infrastructure check (Verifies DB connections & Redis) |

### Public Authentication (No Token Required)
| Method | Endpoint | Description |
|--------|---------|-------------|
| POST | `/v1/auth/register` | Create a new user (automatically syncs to User Service) |
| POST | `/v1/auth/login` | Authenticate -> returns JWT |
| POST | `/v1/auth/forgot-password` | Dispatch a password reset email |
| POST | `/v1/auth/reset-password` | Fulfill password reset token |
| POST | `/v1/auth/mfa/challenge` | Fulfill a Two-Factor step-up login token |

### Protected Authentication (Requires `Authorization: Bearer <Token>`)
| Method | Endpoint | Description |
|--------|---------|-------------|
| GET | `/v1/auth/me` | Fetch active user credentials and settings |
| GET | `/v1/auth/sessions` | List all active device sessions |
| DELETE | `/v1/auth/sessions/{id}` | Revoke a login session from another device |
| POST | `/v1/auth/sessions/revoke-all` | Panic button: Kills all sessions except current |
| POST | `/v1/auth/refresh` | Renew an expiring JWT |
| POST | `/v1/auth/logout` | Safely invalidate the current active token |

### Multi-Factor Setup
| Method | Endpoint | Description |
|--------|---------|-------------|
| POST | `/v1/auth/mfa/setup` | Generate a new MFA Secret and setup QR Code |
| POST | `/v1/auth/mfa/verify` | Confirm and enforce MFA using a challenge code |
| POST | `/v1/auth/mfa/disable` | Turn off MFA processing |

### IAM: User Management
| Method | Endpoint | Description |
|--------|---------|-------------|
| GET | `/v1/users` | List global users |
| GET | `/v1/users/{id}` | Access a specific user's detailed information |
| POST | `/v1/users/{id}/roles` | Hard-assign an RBAC role to a user |
| PATCH | `/v1/users/{id}/status` | Activate, deactivate, or suspend a user account |

### IAM: Roles & Permissions
| Method | Endpoint | Description |
|--------|---------|-------------|
| GET | `/v1/roles` | List hierarchal user capacities |
| POST | `/v1/roles` | Register a custom Enterprise Role matrix |
| GET | `/v1/permissions` | Browse available access flags |

### Organzational Multi-Tenancy
| Method | Endpoint | Description |
|--------|---------|-------------|
| GET | `/v1/organizations` | View organizations |
| POST | `/v1/organizations` | Onboard a new business entity |
| POST | `/v1/organizations/{id}/teams` | Create a specialized operational team |
| POST | `/v1/organizations/{id}/teams/{tid}/members` | Link an existing user onto an organizational team |
