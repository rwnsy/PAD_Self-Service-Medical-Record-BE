# Security Review — Self Medical Record

**Audit Date:** 2026-11-05  
**Auditor:** Automated backend audit

---

## Summary

| Severity | Count |
|----------|-------|
| 🔴 Critical | 0 |
| 🟠 High | 1 |
| 🟡 Medium | 4 |
| 🟢 Low / Informational | 6 |

---

## Findings

### 🟠 HIGH — Missing Database Indexes on `health_records`

**Location:** `database/migrations/2026_09_30_225711_create_self_medical_record_foundation_tables.php`

The `health_records` table has only its primary key index. All queries filter and sort on `user_id`, `recorded_at`, and `warning_status`, but none of these have indexes.

**Impact:** Under load, every health record query performs a full table scan. This becomes a performance-to-security issue if exploited via DoS (slow queries degrade the entire database connection pool).

**Recommendation:** Add a migration with:
```php
$table->index('user_id');
$table->index(['user_id', 'recorded_at']);
$table->index('warning_status');
```

---

### 🟡 MEDIUM — No Rate Limiting on Registration and Password Reset

**Location:** `app/Providers/AppServiceProvider.php`, `routes/api.php`

Only `POST /api/login` has rate limiting (`throttle:login`, 5/min per email+IP). The following endpoints have no throttling:
- `POST /api/register`
- `POST /api/email/verify`
- `POST /api/email/resend-code`
- `POST /api/password/forgot`
- `POST /api/password/verify-code`
- `POST /api/password/reset`
- `POST /api/auth/google`

**Impact:** Attackers can enumerate email addresses (forgot password returns different messages), brute-force verification codes, or spam registrations.

**Recommendation:** Apply `throttle:api` to all public auth endpoints; create a stricter limiter for password reset and email verification.

---

### 🟡 MEDIUM — Google Token Verification via External HTTP Call

**Location:** `app/Services/GoogleIdTokenVerifier.php`

Google ID tokens are verified by calling `https://oauth2.googleapis.com/tokeninfo?id_token=...`. This approach:
1. Makes an external HTTP call per login attempt (latency + availability dependency)
2. Does not use Google's public keys for local JWT verification

**Impact:** If Google's tokeninfo endpoint is unavailable, all Google logins fail (503). The endpoint also reveals the raw token to Google on each call (acceptable per Google's design, but worth noting).

**Recommendation (long-term):** Consider using Google's public keys (`https://www.googleapis.com/oauth2/v3/certs`) with a JWT library for local verification. Short-term: add retry/circuit-breaker logic and ensure `GOOGLE_ID_TOKEN_AUDIENCE` is documented as required.

---

### 🟡 MEDIUM — `APP_DEBUG=true` in `.env.example`

**Location:** `.env.example`

The example environment file ships with `APP_DEBUG=true`. If a developer deploys without changing this, full stack traces will be exposed in API error responses.

**Recommendation:** Change `.env.example` to `APP_DEBUG=false` and add documentation that it must be `false` in production.

---

### 🟡 MEDIUM — `bmi_category`, `warning_status`, `warning_message` in `HealthRecord` `#[Fillable]`

**Location:** `app/Models/HealthRecord.php`

These fields are in `#[Fillable]`, meaning `create()` and `fill()` could accept them from request data. The controller currently always overwrites them with server-calculated values:

```php
$healthRecord = $request->user()->healthRecords()->create([
    ...$validated,       // ← client input
    ...$calculated,      // ← server calculation overwrites
    'bmi_category' => $selfAssessment->bmiCategory($bmiFullPrecision), // ← overwrites
]);
```

This is safe **because** the spread order places server values after client values, but it's fragile. A future developer might change the order accidentally.

**Recommendation:** Remove `bmi_category`, `warning_status`, and `warning_message` from `#[Fillable]`. Use `forceFill()` for server-calculated fields to make the intent explicit and safe by default.

---

### 🟢 LOW — `source` Field in `PersonalMedicalRecord` Fillable

**Location:** `app/Models/PersonalMedicalRecord.php`, `app/Http/Controllers/Api/PersonalMedicalRecordController.php`

The `source` field is in `#[Fillable]` but the controller always hardcodes `'source' => 'USER_REPORTED'`. Same pattern as `bmi_category` above — safe now, fragile by design.

**Recommendation:** Remove `source` from `#[Fillable]` and use `forceFill(['source' => 'USER_REPORTED'])` in the controller.

---

### 🟢 LOW — NIK Privacy (Properly Handled)

**Verified:** NIK is stored encrypted (`encrypted` cast), looked up via HMAC-SHA256 hash (`nik_hash`), hidden from all API responses (`#[Hidden]`), and never appears in URLs. The OpenMRS lookup uses NIK but does not expose it in API responses.

No action required. This is correctly implemented.

---

### 🟢 LOW — OpenMRS Credentials (Properly Handled)

**Verified:** `openmrs_configs.credential` uses Laravel's `encrypted` cast (`'credential' => 'encrypted'` in `OpenmrsConfig::casts()`). The field is also in `#[Hidden]`. The `MedicalRecordController` test verifies credentials are not exposed.

No action required. This was flagged as a potential issue but is correctly implemented.

---

### 🟢 LOW — Avatar XSS (Properly Handled)

**Location:** `app/Http/Controllers/Api/AvatarController.php`

The name query parameter is sanitized with `htmlspecialchars($initials, ENT_XML1, 'UTF-8')` before embedding in SVG. The SVG is returned with `Content-Type: image/svg+xml`, which prevents browsers from executing scripts.

No action required.

---

### 🟢 LOW — Directory Traversal on Food Photo Access (Properly Handled)

**Location:** `app/Http/Controllers/Api/CalorieEstimationController.php`

The `showImage()` method uses `basename($filename)` to strip any path components, preventing directory traversal attacks. Files are stored in a private disk with no public URL.

No action required.

---

### 🟢 LOW — `avatar_path` Mass Assignment (Properly Handled)

**Verified:** `avatar_path` is NOT in `#[Fillable]` on `User`. The `ProfileController` uses `forceFill(['avatar_path' => $path])` for internal writes. The field is in `#[Hidden]`.

No action required.

---

## CORS Configuration

`config/cors.php` does not exist as a custom file — Laravel's default CORS configuration is active. The default allows all origins for API routes.

**Recommendation:** Create `config/cors.php` and restrict `allowed_origins` to known client domains (Android app package origin, web frontend domain) in production.

---

## Authentication Token Notes

- Token name is always `android-api` — suitable for single-client apps
- Tokens are not rotated on login (by design with Sanctum)
- No token expiry configured (`sanctum.expiration` is null by default = tokens never expire)

**Recommendation:** Consider setting `SANCTUM_EXPIRATION` (minutes) in `.env` for production deployments.

