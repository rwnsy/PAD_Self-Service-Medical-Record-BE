# Backend Inventory — Self Medical Record

**Audit Date:** 2026-11-05  
**Framework:** Laravel 13.34 / PHP 8.4.1  
**Database:** PostgreSQL  
**Auth:** Laravel Sanctum 4.3.3

---

## 1. Architecture Overview

```
Android Client
      │  Bearer Token (Sanctum)
      ▼
routes/api.php  ──►  FormRequest (validation)
      │
      ▼
Controller (Api\*)
      │
      ├──► Service (business logic: SelfAssessmentService, CalorieEstimationService,
      │             OpenMrsService, GoogleIdTokenVerifier, VerificationCodeService)
      │
      └──► Eloquent Model ──► PostgreSQL
```

- **No API versioning** — all routes flat under `/api/`
- **No Laravel API Resources** — raw Eloquent serialization
- **No Repository pattern** — business logic in Controllers + Services
- **No shared response trait** — each controller has private `success()`/`failure()` helpers (6 controllers × 2 methods = duplication)
- **Ownership enforcement:** `findOwnedRecord()` pattern inline per controller (no Policies)
- **Response envelope:** `{ success: bool, message: string, data: mixed }` — consistent

---

## 2. Endpoint Inventory

### Public Endpoints (no auth)

| Method | Path | Controller@method | Throttle | Status |
|--------|------|-------------------|----------|--------|
| POST | `/api/register` | AuthController@register | — | ✅ Implemented & tested |
| POST | `/api/login` | AuthController@login | throttle:login (5/min per email+IP) | ✅ Implemented & tested |
| POST | `/api/email/verify` | AuthController@verifyEmail | — | ✅ Implemented & tested |
| POST | `/api/email/resend-code` | AuthController@resendEmailCode | — | ✅ Implemented & tested |
| POST | `/api/password/forgot` | AuthController@forgotPassword | — | ✅ Implemented & tested |
| POST | `/api/password/verify-code` | AuthController@verifyPasswordResetCode | — | ✅ Implemented & tested |
| POST | `/api/password/reset` | AuthController@resetPassword | — | ✅ Implemented & tested |
| POST | `/api/auth/google` | AuthController@google | — | ✅ Implemented & tested |
| GET | `/api/avatar/default` | AvatarController@default | — | ✅ Implemented & tested |
| GET | `/api/calorie-estimation/image/{filename}` | CalorieEstimationController@showImage | middleware:signed | ✅ Implemented & tested |

### Protected Endpoints (auth:sanctum)

| Method | Path | Controller@method | Status |
|--------|------|-------------------|--------|
| POST | `/api/logout` | AuthController@logout | ✅ Implemented & tested |
| GET | `/api/me` | AuthController@me | ✅ Implemented & tested |
| PUT\|PATCH | `/api/profile` | ProfileController@update | ✅ Implemented & tested |
| POST | `/api/profile/photo` | ProfileController@uploadPhoto | ✅ Implemented & tested |
| DELETE | `/api/profile/photo` | ProfileController@deletePhoto | ✅ Implemented & tested |
| GET | `/api/health-records` | HealthRecordController@index | ✅ Implemented & tested |
| POST | `/api/health-records` | HealthRecordController@store | ✅ Implemented & tested |
| GET | `/api/health-records/{id}` | HealthRecordController@show | ✅ Implemented & tested |
| PUT\|PATCH | `/api/health-records/{id}` | HealthRecordController@update | ✅ Implemented & tested |
| DELETE | `/api/health-records/{id}` | HealthRecordController@destroy | ✅ Implemented & tested |
| GET | `/api/dashboard` | DashboardController@index | ✅ Implemented & tested |
| GET | `/api/medical-records` | MedicalRecordController@index | ✅ Implemented (OpenMRS, partially tested) |
| GET | `/api/personal-medical-records` | PersonalMedicalRecordController@index | ✅ Implemented & tested |
| POST | `/api/personal-medical-records` | PersonalMedicalRecordController@store | ✅ Implemented & tested |
| GET | `/api/personal-medical-records/{id}` | PersonalMedicalRecordController@show | ✅ Implemented & tested |
| PUT\|PATCH | `/api/personal-medical-records/{id}` | PersonalMedicalRecordController@update | ✅ Implemented & tested |
| DELETE | `/api/personal-medical-records/{id}` | PersonalMedicalRecordController@destroy | ✅ Implemented & tested |
| POST | `/api/calorie-estimation` | CalorieEstimationController@estimate | ✅ Implemented (mock), tested |

**Gap:** No `GET /api/profile` endpoint — only `GET /api/me` exists for reading current user data.

### Non-Production Web Routes (middleware: non-production)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/calorie-estimation` | Dev preview UI for calorie estimation |
| GET | `/profile-photo-test` | Dev preview UI for profile photo upload |
| GET | `/google-login-test` | Dev preview UI for Google login |

---

## 3. Feature Matrix

| Feature | Implemented | Tests | Mock/Real | External Deps |
|---------|-------------|-------|-----------|---------------|
| Registration | ✅ | ✅ | Real | — |
| Email Verification | ✅ | ✅ | Real | Mail driver |
| Password Reset | ✅ | ✅ | Real | Mail driver |
| Login (Email) | ✅ | ✅ | Real | — |
| Login (Google) | ✅ | ✅ | Real | `oauth2.googleapis.com/tokeninfo` |
| Logout | ✅ | ✅ | Real | — |
| Profile Read (`/me`) | ✅ | ✅ | Real | — |
| Profile Update | ✅ | ✅ | Real | — |
| Profile Photo Upload | ✅ | ✅ | Real | Storage::disk('public') |
| Profile Photo Delete | ✅ | ✅ | Real | Storage::disk('public') |
| Local SVG Avatar | ✅ | ✅ | Real | — |
| Self-Assessment (BMI+BP) | ✅ | ✅ | Real | — |
| Health Record CRUD | ✅ | ✅ | Real | — |
| Health Record Date Filter | ✅ | ✅ | Real | — |
| Health Record Warning Filter | ✅ | ✅ | Real | — |
| Dashboard (summary + charts) | ✅ | ✅ | Real | — |
| OpenMRS Medical Records | ✅ | ✅ | Real* | OpenMRS REST API |
| Personal Medical Records CRUD | ✅ | ✅ | Real | — |
| Food Calorie Estimation | ✅ | ✅ | **Mock** | (AI not integrated) |
| Private Food Photo Storage | ✅ | ✅ | Real | Storage::disk('local') |
| Signed URL Photo Access | ✅ | ✅ | Real | — |
| Calorie Photo Pruning | ✅ | ✅ | Real | Scheduler (daily) |

*OpenMRS: Real integration, but reads from `openmrs_configs` table (must be seeded in DB). Tests mock HTTP layer.

---

## 4. Database Schema

### Tables

| Table | Rows (est.) | Primary Key | Notes |
|-------|------------|-------------|-------|
| `roles` | 2 (USER, ADMIN) | `role_id` | Seeded |
| `users` | — | `user_id` | UUID strategy via `bigIncrements` |
| `health_records` | — | `health_record_id` | ⚠️ Missing indexes |
| `personal_medical_records` | — | `personal_medical_record_id` | Has composite index |
| `photos` | — | `photo_id` | ⚠️ Dead table (no routes/controller) |
| `integration_logs` | — | `id` | Written by OpenMrsService |
| `activity_logs` | — | `id` | ⚠️ Dead table (no service writes to it) |
| `openmrs_configs` | — | `config_id` | Admin-managed |
| `verification_codes` | — | `id` | Used by auth flow |
| `personal_access_tokens` | — | `id` | Sanctum tokens |
| `cache` | — | `key` | Laravel cache |
| `jobs` | — | `id` | Laravel queue |

### Critical Missing Indexes

```
health_records:
  ⚠️  MISSING: (user_id)              — every query filters by user_id
  ⚠️  MISSING: (user_id, recorded_at) — dashboard trend + filter queries
  ⚠️  MISSING: (warning_status)       — filter by warning_status
  ⚠️  MISSING: (recorded_at)          — order by recorded_at DESC
```

The `health_records` table currently only has its primary key index. All queries on this table perform full table scans filtered server-side, which will degrade significantly with scale.

### Key Column Decisions

| Column | Table | Storage | Notes |
|--------|-------|---------|-------|
| `nik` | `users` | Laravel `encrypted` cast | Decrypts transparently |
| `nik_hash` | `users` | HMAC-SHA256 (app.key) | Used for uniqueness checks |
| `phone` | `users` | Laravel `encrypted` cast | — |
| `credential` | `openmrs_configs` | Laravel `encrypted` cast | ✅ Correctly encrypted |
| `password` | `users` | bcrypt (`hashed` cast) | Nullable for Google-only users |
| `recorded_at` | `health_records` | `timestampTz` | Mutator normalizes to Asia/Jakarta |
| `bmi_category` | `health_records` | `varchar(24)` | Calculated from full-precision BMI |

---

## 5. Validation Rules Summary

| Endpoint | Key Rules |
|----------|-----------|
| `POST /register` | email unique, password min:8, NIK 16 digits unique (by hash), name optional |
| `PUT/PATCH /profile` | email unique (ignore self), NIK unique (ignore self), gender in MALE/FEMALE, dob after 1900 before today |
| `POST /health-records` | At least height+weight or systolic+diastolic; systolic > diastolic; recorded_at ≤ now |
| `PUT/PATCH /health-records/{id}` | Same as store (all fields nullable/optional) |
| `GET /health-records` | Date filter, warning_status in NORMAL/ATTENTION/URGENT, per_page 1–100 |
| `GET /dashboard` | period in week/month/3months/6months |
| `POST /personal-medical-records` | visit_date ≤ today, facility_name required max:255, complaint required |
| `POST /calorie-estimation` | image required, jpeg/jpg/png/webp, max 5120 KB |
| `POST /profile/photo` | photo required, jpeg/jpg/png/webp, max 5120 KB |

---

## 6. Service Layer

| Service | Purpose | External? |
|---------|---------|-----------|
| `SelfAssessmentService` | BMI calculation, blood pressure classification | No |
| `CalorieEstimationService` | Mock calorie estimation, private storage, signed URL | No (mock) |
| `OpenMrsService` | OpenMRS REST API patient + encounter lookup | Yes (HTTP) |
| `GoogleIdTokenVerifier` | Verify Google ID token via tokeninfo endpoint | Yes (HTTP) |
| `VerificationCodeService` | Email verification code generation, rate limiting | No |

---

## 7. Test Coverage

| Test File | Tests | Status |
|-----------|-------|--------|
| `AuthenticationTest.php` | 7 | ✅ |
| `EmailAuthenticationFlowTest.php` | ~15 | ✅ |
| `HealthRecordTest.php` | ~20 | ✅ |
| `MedicalRecordTest.php` | ~8 | ✅ |
| `PersonalMedicalRecordTest.php` | ~12 | ✅ |
| `DashboardTest.php` | 10 | ✅ |
| `ProfileTest.php` | 5 | ✅ |
| `ProfilePhotoTest.php` | 11 | ✅ |
| `CalorieEstimationTest.php` | 7 | ✅ |
| `ExampleTest.php` | 1 | ✅ |
| **Total** | **122** | **✅ All Pass** |

---

## 8. Known Gaps & Dead Code

| Item | Type | Severity |
|------|------|----------|
| No `GET /api/profile` endpoint | Gap | Medium |
| No API versioning | Architecture gap | Low (by design) |
| No Laravel API Resources | Architecture gap | Low |
| `photos` table — no routes/controller | Dead table | Low |
| `activity_logs` table — no service writes | Dead table | Low |
| `User::photos()` relation — unused | Dead relation | Low |
| `User::activityLogs()` relation — unused | Dead relation | Low |
| `EnsureUserHasRole` middleware — defined but not applied to any route | Unused middleware | Low |
| `bmi_category` in `#[Fillable]` on `HealthRecord` | Potential confusion | Low (controller always overwrites) |
| `warning_status`, `warning_message` in `#[Fillable]` on `HealthRecord` | Potential confusion | Low (controller always recalculates) |
| No rate limiting on registration, password reset | Missing throttle | Medium |
| `calorie-estimation.image` route — no authentication required (only signed URL) | By design | — |
| Dashboard runs 5 separate queries (latestBmi, latestBp, latestWarning, totalAssessments, lastRecord) | Performance | Medium |
| Calorie estimation is 100% mock (`is_mock: true`) | Feature gap | High (expected) |

---

## 9. External Dependencies

| Dependency | Purpose | Failure behavior |
|-----------|---------|-----------------|
| `oauth2.googleapis.com/tokeninfo` | Google token verification | 503 response |
| OpenMRS REST API | Medical records read | 503 response, logged in `integration_logs` |
| Mail driver (SMTP) | Email verification, password reset | 503 response in profile update |

---

## 10. Configuration Notes

- `DB_TIMEZONE=Asia/Jakarta` — set on PostgreSQL connection session
- `APP_TIMEZONE` — defaults to UTC (Laravel default); `recorded_at` normalization handled in model mutator
- Login rate limit: 5 requests/minute per email+IP
- No rate limiting on registration, email verification, or password reset endpoints
- Cache driver: `database` (`.env.example`) — no Redis
- Session driver: `database` (`.env.example`) — no Redis
- Queue driver: `database` (`.env.example`) — no Redis
- `GOOGLE_ID_TOKEN_AUDIENCE` — must be set for Google auth to work
- Storage symlink required: `php artisan storage:link`
- Calorie images pruned daily via scheduled `calorie-estimates:prune` command

