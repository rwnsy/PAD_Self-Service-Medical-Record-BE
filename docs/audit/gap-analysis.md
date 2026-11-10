# Gap Analysis & Technical Debt — Self Medical Record

**Audit Date:** 2026-11-05  
**Scope:** Architecture, Security, API Consistency, Android Readiness, Maintainability

---

## Executive Summary

| Priority | Count | Description |
|---|---|---|
| 🔴 **Critical** | 0 | No blocking security flaws or fatal system bugs remaining. |
| 🟠 **High** | 3 | Real AI model integration needed; missing auth rate limiters; profile endpoint naming. |
| 🟡 **Medium** | 5 | Code duplication in controllers; 7 dashboard queries; missing API Resources; missing API versioning; dead DB tables. |
| 🟢 **Low** | 3 | Unused middleware; database session driver; missing CORS whitelist config. |

---

## 1. High Priority Items

### GAP-01: Food Calorie Estimation Service is 100% Mock
- **Status:** Open (By Design for current phase)
- **Impact:** High for product functionality. The endpoint `POST /api/calorie-estimation` returns hardcoded dummy components (Salmon, Nasi, Alpukat, etc.) with `is_mock: true`.
- **Files:** `app/Services/CalorieEstimationService.php`, `app/Services/Contracts/CalorieEstimationServiceInterface.php`.
- **Recommendation:** Implement a real AI vision service implementing `CalorieEstimationServiceInterface` (e.g., Gemini 1.5/2.0 Flash multimodal API) when credentials and prompt schemas are ready. The clean contract pattern already in place ensures zero breaking changes to controllers or mobile clients.
- **Complexity:** Medium.

### GAP-02: Missing Rate Limiting on Sensitive Public Auth Endpoints
- **Status:** Open
- **Impact:** Registration, password reset code generation, email verification, and Google token validation are not throttled. Only `POST /api/login` has `throttle:login`.
- **Files:** `app/Providers/AppServiceProvider.php`, `routes/api.php`.
- **Risk:** Automated credential stuffing, email enumeration, SMS/email spam abuse.
- **Recommendation:** Define rate limiters in `AppServiceProvider`:
  - `throttle:register` (e.g., 5 per minute per IP)
  - `throttle:password-reset` (e.g., 3 per minute per IP/email)
- **Complexity:** Small.

### GAP-03: Endpoint Naming: `GET /api/me` vs `GET /api/profile`
- **Status:** Open (Documented in API Contract)
- **Impact:** Profile updates occur on `PUT|PATCH /api/profile`, but retrieving profile data uses `GET /api/me`. Android developers might intuitively request `GET /api/profile`.
- **Files:** `routes/api.php`.
- **Recommendation:** Add a route alias `Route::get('/profile', [AuthController::class, 'me'])` or maintain `GET /api/me` as the single canonical profile retrieval endpoint in the API contract.
- **Complexity:** Small.

---

## 2. Medium Priority Items (Technical Debt)

### GAP-04: Duplicated Response Helpers in Controllers
- **Status:** Open
- **Impact:** `success()` and `failure()` private methods are duplicated verbatim across 5 controllers:
  - `AuthController.php`
  - `ProfileController.php`
  - `HealthRecordController.php`
  - `MedicalRecordController.php`
  - `PersonalMedicalRecordController.php`
- **Recommendation:** Extract these methods into an `App\Http\Controllers\Concerns\ApiResponse` trait or base `Controller` class.
- **Complexity:** Small.

### GAP-05: Missing Laravel API Resources (JsonResource)
- **Status:** Open
- **Impact:** Raw Eloquent models are returned directly. While `$hidden` arrays prevent sensitive fields (`nik`, `password`, `nik_hash`) from leaking, Eloquent serialization is harder to version and maintain than dedicated `JsonResource` classes.
- **Recommendation:** Create `UserResource`, `HealthRecordResource`, `PersonalMedicalRecordResource`, and `DashboardResource` for consistent transformation.
- **Complexity:** Medium.

### GAP-06: Dashboard Executes 7 Queries per Load
- **Status:** Open
- **Impact:** `DashboardController@index` runs 7 separate database queries:
  1. Latest BMI record
  2. Latest BP record
  3. Latest warning record
  4. Total assessments count
  5. Last record date
  6. BMI trend array
  7. Blood pressure trend array
- **Recommendation:** Combine queries using subqueries, window functions, or a cached summary per user.
- **Complexity:** Medium.

### GAP-07: Dead Database Tables & Relations
- **Status:** Open
- **Impact:** Tables `photos` and `activity_logs` exist in the database schema from foundation migration `2026_09_30_225711`, but no controller or service writes to them. The `User` model defines `hasMany(Photo::class)` and `hasMany(ActivityLog::class)` which are never utilized.
- **Recommendation:** Either implement activity logging / photo storage features, or drop dead tables in a future cleanup migration.
- **Complexity:** Small.

### GAP-08: No API Versioning Prefix
- **Status:** Open
- **Impact:** All endpoints reside directly under `/api/*` instead of `/api/v1/*`. Breaking changes in the future will require URL restructuring or header versioning.
- **Recommendation:** Introduce `/api/v1` route grouping in future major revisions.
- **Complexity:** Medium (affects client routes).

---

## 3. Low Priority Items

### GAP-09: Unused `EnsureUserHasRole` Middleware
- **Status:** Open
- **Impact:** `app/Http/Middleware/EnsureUserHasRole.php` exists and checks `$request->user()->role->role_name`, but is not registered or assigned to any route in `bootstrap/app.php` or `routes/api.php`.
- **Recommendation:** Assign alias `'role' => EnsureUserHasRole::class` in `bootstrap/app.php` if admin-only routes (such as OpenMRS config management) are added in the future.

### GAP-10: Database Driver for Cache, Queue, and Sessions
- **Status:** Open
- **Impact:** In high-concurrency production deployments, reading and writing sessions and cache to PostgreSQL can cause table bloat and connection exhaustion.
- **Recommendation:** Use Redis or Memcached in production (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`).

### GAP-11: Explicit CORS Configuration
- **Status:** Open
- **Impact:** `config/cors.php` relies on default framework fallback.
- **Recommendation:** Publish `config/cors.php` and configure explicit allowed origins for mobile and web production domains.

