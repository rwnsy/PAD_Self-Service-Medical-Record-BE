# Admin Module — Inventory & Implementation Report

## 1. Role System Status

| Component | Status | Notes |
|-----------|--------|-------|
| `roles` table | ✅ Exists | `role_id`, `role_name` (unique) |
| `USER` role | ✅ Seeded | `RoleSeeder` upserts `USER` and `ADMIN` |
| `ADMIN` role | ✅ Seeded | `RoleSeeder` upserts `USER` and `ADMIN` |
| `EnsureUserHasRole` middleware | ✅ Working | Alias `role` registered in `bootstrap/app.php` |
| Login response includes role | ✅ | `load('role')` in `authenticated()` and `me()` |

## 2. Database Tables

### `activity_logs`
| Column | Type | Notes |
|--------|------|-------|
| activity_id | PK bigint | |
| user_id | FK (nullable) | |
| action | string | e.g. LOGIN, LOGOUT, HEALTH_RECORD_CREATE |
| description | text nullable | |
| created_at / updated_at | timestamps | |

**Indexes added:** `activity_logs_user_id_index`, `activity_logs_created_at_index`, `activity_logs_action_index`

### `integration_logs`
| Column | Type | Notes |
|--------|------|-------|
| log_id | PK bigint | |
| user_id | FK (nullable) | |
| operation | string | |
| status | string | |
| request_data | jsonb (hidden) | |
| response_data | jsonb (hidden) | |
| error_message | text (hidden) | |
| attempted_at | timestampTz | |

**Indexes added:** `integration_logs_user_id_index`, `integration_logs_status_index`, `integration_logs_attempted_at_index`

### `openmrs_configs`
| Column | Type | Notes |
|--------|------|-------|
| config_id | PK bigint | |
| endpoint_url | string | |
| credential | text (encrypted) | JSON: `{"username":"…","password":"…"}` |
| is_active | boolean | |
| updated_by | FK nullable | |

> [!IMPORTANT]
> `credential` is cast as `'encrypted'` in `OpenmrsConfig` and is `#[Hidden]`. It is **never** returned in any API response.

## 3. Admin Endpoints

All routes under `auth:sanctum` + `role:ADMIN` middleware, prefix `/api/admin`.

| Method | Path | Controller | Description |
|--------|------|-----------|-------------|
| GET | `/api/admin/dashboard` | `AdminDashboardController@index` | Aggregated stats |
| GET | `/api/admin/users` | `AdminUserController@index` | Paginated user list (no PII) |
| GET | `/api/admin/users/{userId}` | `AdminUserController@show` | Single user (no PII) |
| GET | `/api/admin/users/{userId}/health-records` | `AdminUserController@healthRecords` | User's health records |
| GET | `/api/admin/users/{userId}/personal-medical-records` | `AdminUserController@personalMedicalRecords` | User's personal records |
| GET | `/api/admin/activity-logs` | `AdminActivityLogController@index` | Activity log listing |
| GET | `/api/admin/integration-logs` | `AdminIntegrationLogController@index` | Integration log listing |
| GET | `/api/admin/openmrs/config` | `AdminOpenMrsController@getConfig` | View OpenMRS config (no credential) |
| PUT | `/api/admin/openmrs/config` | `AdminOpenMrsController@updateConfig` | Update OpenMRS config |
| POST | `/api/admin/openmrs/test-connection` | `AdminOpenMrsController@testConnection` | Test OpenMRS connectivity |
| GET | `/api/admin/system-logs` | `AdminSystemLogController@index` | View sanitized system logs |

## 4. Activity Logging

`ActivityLogService` is a singleton registered in `AppServiceProvider`. It is injected via `app()` in controllers and **always wraps writes in try/catch** — a database error never fails the parent request.

| Controller | Actions logged |
|-----------|---------------|
| `AuthController` | `LOGIN`, `LOGOUT` |
| `ProfileController` | `PROFILE_UPDATE`, `PROFILE_PHOTO_UPLOAD`, `PROFILE_PHOTO_DELETE` |
| `HealthRecordController` | `HEALTH_RECORD_CREATE`, `HEALTH_RECORD_UPDATE`, `HEALTH_RECORD_DELETE` |
| `PersonalMedicalRecordController` | `PERSONAL_RECORD_CREATE`, `PERSONAL_RECORD_UPDATE`, `PERSONAL_RECORD_DELETE` |
| `AdminUserController` | `ADMIN_USER_VIEW`, `ADMIN_HEALTH_RECORDS_VIEW`, `ADMIN_PERSONAL_RECORDS_VIEW` |
| `AdminSystemLogController` | `ADMIN_SYSTEM_LOGS_VIEW` |
| `AdminOpenMrsController` | `OPENMRS_CONFIG_UPDATE`, `ADMIN_OPENMRS_TEST_CONNECTION` |

## 5. Security Guarantees

- All admin endpoints require BOTH `auth:sanctum` AND `role:ADMIN` — enforced server-side.
- `EnsureUserHasRole` reads role from DB, not from client.
- PII fields (`nik`, `phone`, `nik_hash`, `google_sub`, `password`, `avatar_path`) are excluded from all admin user responses via explicit `safeUser()` mapping.
- OpenMRS `credential` is never returned in any response.
- OpenMRS SSRF protection: strict HTTPS enforcement in production, rejection of private IPs / cloud metadata / loopback addresses, host allowlist support, and `withoutRedirecting()` HTTP requests.
- Activity logging is silent — never breaks parent requests.
- Sensitive data is redacted from system log outputs.
- Dedicated rate limiting on `test-connection` (10/min), `system-logs` (30/min), and `dashboard` (60/min).

## 6. Test Coverage

| Suite | Tests | Assertions | Status | Duration |
|-------|-------|------------|--------|----------|
| `AdminTest.php` | 50 | 167 | Passed | 6.83s |
| Full Test Suite | 196 | 777 | Passed | 23.81s |

**Final test count:** 196 passed, 777 assertions (baseline was 146 tests, 610 assertions before admin module).
