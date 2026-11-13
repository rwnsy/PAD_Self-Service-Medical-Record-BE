# Admin Module — Implementation Report

**Project:** Self Medical Record  
**Backend Stack:** Laravel 13 / PHP 8.4 / PostgreSQL / Laravel Sanctum  
**Report Date:** 2026-11-10

---

## 1. Ringkasan Implementasi

Modul Admin telah selesai diimplementasikan secara penuh mencakup 11 endpoint REST API yang dilindungi oleh middleware `auth:sanctum` + `role:ADMIN`. Semua endpoint terpisah dari endpoint pengguna biasa dengan prefix `/api/admin`. Tidak ada tabel baru yang dibuat — seluruh implementasi menggunakan tabel dan model yang sudah tersedia.

**Baseline sebelum:** 146 tests, 610 assertions  
**Setelah implementasi admin:** 196 tests, 777 assertions (50 tests khusus AdminTest.php, 167 assertions, 0 failures)

---

## 2. Struktur Modul Admin

```
app/Http/Controllers/Api/Admin/
├── AdminDashboardController.php
├── AdminUserController.php
├── AdminActivityLogController.php
├── AdminIntegrationLogController.php
├── AdminOpenMrsController.php
└── AdminSystemLogController.php

app/Http/Requests/Admin/
└── UpdateOpenMrsConfigRequest.php

app/Services/
└── ActivityLogService.php
```

---

## 3. Daftar API Admin

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/admin/dashboard` | AdminDashboardController@index | ✅ Implemented & Tested |
| GET | `/api/admin/users` | AdminUserController@index | ✅ Implemented & Tested |
| GET | `/api/admin/users/{userId}` | AdminUserController@show | ✅ Implemented & Tested |
| GET | `/api/admin/users/{userId}/health-records` | AdminUserController@healthRecords | ✅ Implemented & Tested |
| GET | `/api/admin/users/{userId}/personal-medical-records` | AdminUserController@personalMedicalRecords | ✅ Implemented & Tested |
| GET | `/api/admin/activity-logs` | AdminActivityLogController@index | ✅ Implemented & Tested |
| GET | `/api/admin/integration-logs` | AdminIntegrationLogController@index | ✅ Implemented & Tested |
| GET | `/api/admin/openmrs/config` | AdminOpenMrsController@getConfig | ✅ Implemented & Tested |
| PUT | `/api/admin/openmrs/config` | AdminOpenMrsController@updateConfig | ✅ Implemented & Tested |
| POST | `/api/admin/openmrs/test-connection` | AdminOpenMrsController@testConnection | ✅ Implemented & Tested |
| GET | `/api/admin/system-logs` | AdminSystemLogController@index | ✅ Implemented & Tested |

---

## 4. Daftar File yang Dibuat/Diubah

### File Baru

| File | Keterangan |
|------|-----------|
| `app/Services/ActivityLogService.php` | Silent singleton activity logger |
| `app/Services/OpenMrsUrlValidator.php` | OpenMRS URL SSRF validator & policy enforcer |
| `app/Http/Controllers/Api/Admin/AdminDashboardController.php` | Dashboard stats |
| `app/Http/Controllers/Api/Admin/AdminUserController.php` | User management (read-only) |
| `app/Http/Controllers/Api/Admin/AdminActivityLogController.php` | Activity log monitoring |
| `app/Http/Controllers/Api/Admin/AdminIntegrationLogController.php` | Integration log monitoring |
| `app/Http/Controllers/Api/Admin/AdminOpenMrsController.php` | OpenMRS config management |
| `app/Http/Controllers/Api/Admin/AdminSystemLogController.php` | System log viewer (redacted) |
| `app/Http/Requests/Admin/UpdateOpenMrsConfigRequest.php` | Validation for config update |
| `tests/Feature/AdminTest.php` | Admin module test suite |
| `docs/admin/admin-inventory.md` | Phase 1 audit report |
| `docs/admin/admin-implementation-report.md` | This file |

### File Dimodifikasi

| File | Perubahan |
|------|-----------|
| `routes/api.php` | Tambah 11 admin routes + rate limiters |
| `app/Providers/AppServiceProvider.php` | Register ActivityLogService singleton & admin rate limiters |
| `config/services.php` | Tambah konfigurasi openmrs (allowed_hosts, enforce_https, allow_local_network) |
| `app/Http/Controllers/Api/AuthController.php` | Log LOGIN, LOGOUT |
| `app/Http/Controllers/Api/ProfileController.php` | Log PROFILE_UPDATE, PROFILE_PHOTO_UPLOAD, PROFILE_PHOTO_DELETE |
| `app/Http/Controllers/Api/HealthRecordController.php` | Log HEALTH_RECORD_CREATE/UPDATE/DELETE |
| `app/Http/Controllers/Api/PersonalMedicalRecordController.php` | Log PERSONAL_RECORD_CREATE/UPDATE/DELETE |
| `app/Http/Controllers/Api/Admin/AdminUserController.php` | Audit logging on user/health data access |
| `database/migrations/2026_11_10_144053_add_indexes_to_admin_tables.php` | Indexes for activity_logs & integration_logs |
| `docs/api/openapi.yaml` | Tambah 11 admin endpoints + 4 schemas baru |
| `docs/api/self-medical-record.postman_collection.json` | Tambah folder "8. Admin Management" |

---

## 5. Database Migration

Migration `2026_11_10_144053_add_indexes_to_admin_tables.php` menambahkan:

**`activity_logs`:**
- `activity_logs_user_id_index`
- `activity_logs_created_at_index`
- `activity_logs_action_index`

**`integration_logs`:**
- `integration_logs_user_id_index`
- `integration_logs_status_index`
- `integration_logs_attempted_at_index`

> [!NOTE]
> Tidak ada tabel baru yang dibuat. Semua data menggunakan tabel yang sudah ada.

---

## 6. Authorization & Security

### Middleware Stack

Semua endpoint admin dilindungi oleh **dua lapis middleware**:

```
Route::middleware(['auth:sanctum', 'role:ADMIN'])->prefix('admin')->group(...)
```

1. `auth:sanctum` → Memeriksa keabsahan Sanctum token (HTTP 401 jika gagal)
2. `role:ADMIN` → `EnsureUserHasRole` membaca role dari database, bukan dari client (HTTP 403 jika bukan ADMIN)

### PII Protection

`AdminUserController::safeUser()` secara eksplisit memetakan field yang aman saja:
- ✅ Dikembalikan: `user_id`, `name`, `email`, `gender`, `date_of_birth`, `avatar_url`, `role`, timestamps
- ❌ Tidak dikembalikan: `nik`, `phone`, `nik_hash`, `password`, `google_sub`, `avatar_path`, `remember_token`

### Credential Protection

- `OpenmrsConfig::credential` adalah cast `'encrypted'` dan bertanda `#[Hidden]`
- `AdminOpenMrsController::getConfig()` dan `updateConfig()` **tidak pernah** mengembalikan field `credential`
- Activity log untuk config change hanya mencatat `endpoint_url`, **bukan** username/password

### System Log Redaction

`AdminSystemLogController` menerapkan redaksi pada pola berikut sebelum mengirim ke client:
- `password`, `token`, `credential`, `Authorization`, `nik`, `phone`, `google_sub`, Bearer tokens

---

## 7. Dashboard Metrics

`GET /api/admin/dashboard` menggunakan SQL aggregation queries (bukan PHP looping):

| Metric | Query |
|--------|-------|
| Total users | `User::count()` |
| New users in period | `User::where('created_at', '>=', $from)->count()` |
| Health records in period | `HealthRecord::where('recorded_at', '>=', $from)->count()` |
| Warning distribution | `HealthRecord::groupBy('warning_status')->selectRaw(...)` |
| Integration stats | `IntegrationLog::groupBy('status')->selectRaw(...)` |
| Activity count | `ActivityLog::where('created_at', '>=', $from)->count()` |

Filter periode didukung: `week`, `month` (default), `3months`, `6months`

---

## 8. User Monitoring

### List Users (`GET /api/admin/users`)
- Pagination (default 15, max 100)
- Search by name/email (`ilike` — case insensitive)
- Filter by role name
- Eager load role relationship

### User Detail (`GET /api/admin/users/{userId}`)
- Informasi dasar tanpa PII

### Health Records (`GET /api/admin/users/{userId}/health-records`)
- Paginated, ordered by `recorded_at DESC`

### Personal Medical Records (`GET /api/admin/users/{userId}/personal-medical-records`)
- Paginated, ordered by `visit_date DESC`

> [!IMPORTANT]
> Semua endpoint user monitoring bersifat **read-only**. Tidak ada fitur edit/delete data kesehatan pengguna oleh admin.

---

## 9. Activity Logs

### Aktivitas yang Dicatat

| Action | Controller | Trigger |
|--------|-----------|---------|
| `LOGIN` | AuthController | Email/password login berhasil |
| `LOGIN` | AuthController | Google sign-in berhasil |
| `LOGOUT` | AuthController | Logout request |
| `PROFILE_UPDATE` | ProfileController | PUT/PATCH /profile |
| `PROFILE_PHOTO_UPLOAD` | ProfileController | POST /profile/photo |
| `PROFILE_PHOTO_DELETE` | ProfileController | DELETE /profile/photo |
| `HEALTH_RECORD_CREATE` | HealthRecordController | POST /health-records |
| `HEALTH_RECORD_UPDATE` | HealthRecordController | PUT /health-records/{id} |
| `HEALTH_RECORD_DELETE` | HealthRecordController | DELETE /health-records/{id} |
| `PERSONAL_RECORD_CREATE` | PersonalMedicalRecordController | POST /personal-medical-records |
| `PERSONAL_RECORD_UPDATE` | PersonalMedicalRecordController | PUT /personal-medical-records/{id} |
| `PERSONAL_RECORD_DELETE` | PersonalMedicalRecordController | DELETE /personal-medical-records/{id} |
| `ADMIN_USER_VIEW` | AdminUserController | Admin melihat detail user #ID |
| `ADMIN_HEALTH_RECORDS_VIEW` | AdminUserController | Admin melihat riwayat health records user #ID |
| `ADMIN_PERSONAL_RECORDS_VIEW` | AdminUserController | Admin melihat personal records user #ID |
| `ADMIN_SYSTEM_LOGS_VIEW` | AdminSystemLogController | Admin mengakses system logs |
| `ADMIN_OPENMRS_TEST_CONNECTION` | AdminOpenMrsController | Admin menjalankan test connection OpenMRS |
| `OPENMRS_CONFIG_UPDATE` | AdminOpenMrsController | PUT /admin/openmrs/config |

### Safety Guarantees

- `ActivityLogService::log()` membungkus semua penulisan dalam `try/catch (Throwable)`
- Kegagalan logging **tidak pernah** menyebabkan request utama gagal
- Tidak ada password, token, OTP, credentials, atau NIK yang disimpan ke log
- Admin tidak dapat menghapus activity logs melalui endpoint manapun
- Tindakan monitoring agregat umum (dashboard, list users paginated) tidak di-log secara berlebihan guna menghindari flood audit log

---

## 10. OpenMRS Configuration & SSRF Protection

### Get Config (`GET /api/admin/openmrs/config`)
- Mengembalikan `endpoint_url`, `is_active`, `updated_by`, timestamps
- **Credential tidak pernah dikembalikan**

### Update Config (`PUT /api/admin/openmrs/config`)
- Validasi URL wajib dengan `OpenMrsUrlValidator`
- Credential diterima sebagai `{username, password}` dan disimpan sebagai JSON terenkripsi (Laravel `'encrypted'` cast)
- Perubahan dicatat ke `activity_logs`
- Response tidak menyertakan credential

### SSRF Protection (`OpenMrsUrlValidator`)
- **HTTPS Enforcement:** Di environment production (atau saat `OPENMRS_ENFORCE_HTTPS=true`), hanya protokol `https://` yang diterima.
- **Private & Loopback IP Blocking:** Di production / strict mode, seluruh rentang RFC 1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), loopback (127.0.0.0/8, ::1), link-local (169.254.0.0/16 termasuk cloud metadata `169.254.169.254`), CGNAT (100.64.0.0/10), dan reserved IPs ditolak.
- **DNS Resolution & Rebinding Defense:** Host domain di-resolve ke IP; jika ada IP hasil resolve yang mengarah ke jaringan privat/internal, request ditolak sebelum koneksi terjadi.
- **Redirect Defense:** Request test connection menggunakan `Http::withoutRedirecting()` sehingga respon redirect (301/302) tidak diikuti ke target internal/metadata.
- **Host Allowlist:** Mendukung `OPENMRS_ALLOWED_HOSTS` untuk membatasi host OpenMRS hanya ke domain tertentu yang disetujui.
- **Localhost Support:** Lingkungan lokal/testing secara eksplisit mendukung localhost/127.0.0.1 untuk mempermudah development lokal.

### Test Connection (`POST /api/admin/openmrs/test-connection`)
- Menggunakan konfigurasi aktif dari database
- Divalidasi oleh `OpenMrsUrlValidator` sebelum HTTP request dilakukan
- Hit `/ws/rest/v1/session` dengan `timeout(5)` dan `connectTimeout(3)`
- `Http::withoutRedirecting()` mencegah redirect exploitation
- Memeriksa `authenticated: true` dalam response OpenMRS
- Mencatat hasil ke `integration_logs` (operation: `CONNECTION_TEST`, status: `SUCCESS`/`FAILED`/`TIMEOUT`/`ERROR`)
- Error message yang dikembalikan ke client dan log telah disanitasi (tidak membocorkan credentials atau trace cURL)

---

## 11. System Logs

`GET /api/admin/system-logs`

- Membaca `storage/logs/laravel.log` dari bagian akhir file (tidak load entire file)
- Parse format standar Laravel: `[timestamp] env.LEVEL: message`
- Filter by level: `error`, `warning`, `info`, `debug`, dll
- Limit max 200 entries
- Redaksi otomatis pada pola sensitif (password, token, Bearer, NIK, dll)
- Message dipotong maksimal 500 karakter per baris

---

## 12. Automated Test Results

### Admin Test Suite (`tests/Feature/AdminTest.php`)

```
php artisan test tests/Feature/AdminTest.php --compact
{"tool":"pest","result":"passed","tests":50,"passed":50,"assertions":167,"duration_ms":6833}
```

| Kategori Pengujian | Jumlah Test |
|--------------------|-------------|
| Guest auth guards (401) | 6 |
| USER role guards (403) | 6 |
| Admin dashboard & period filters | 2 |
| User management & safe PII exclusion | 5 |
| User search & role filter | 2 |
| Activity log monitoring & listing | 3 |
| Integration log monitoring | 1 |
| Activity log creation (auth, CRUD, photo) | 5 |
| Activity audit untuk akses data admin | 5 |
| OpenMRS config CRUD & credential hiding | 4 |
| OpenMRS SSRF protection & validation | 5 |
| OpenMRS test connection & timeout handling | 2 |
| Rate limiting (429 verification) | 1 |
| Ownership isolation | 1 |
| System logs viewing & level filtering | 2 |
| **Total Tests AdminTest.php** | **50 Passed (167 Assertions)** |

### Full Test Suite Regression

```
php artisan test --compact
{"tool":"pest","result":"passed","tests":196,"passed":196,"assertions":777,"duration_ms":23806}
```

- **Total tests:** 196 (0 failed, 196 passed)
- **Assertions:** 777
- **Duration:** 23.81s (23806 ms)

---

## 13. Performance Notes

- Indexes ditambahkan pada `activity_logs` (user_id, created_at, action) dan `integration_logs` (user_id, status, attempted_at) untuk mempercepat query monitoring
- Dashboard menggunakan SQL aggregation, bukan PHP iteration
- `AdminUserController` menggunakan `with('user:user_id,name,email')` untuk mencegah N+1 pada log listing
- `AdminUserController` menggunakan `ilike` (PostgreSQL case-insensitive) untuk search yang efisien
- `AdminSystemLogController` membaca file log dari akhir menggunakan `fseek` — tidak memuat seluruh file ke memory

---

## 14. API Contract & Postman Update

### OpenAPI (`docs/api/openapi.yaml`)
- Versi diperbarui ke `1.1.0`
- 11 endpoint admin baru ditambahkan
- 4 schema baru: `AdminUserSafe`, `ActivityLogEntry`, `IntegrationLogEntry`, `OpenMrsConfigResponse`

### Postman Collection (`docs/api/self-medical-record.postman_collection.json`)
- Folder baru **"8. Admin Management"** dengan 7 subfolder:
  - Admin Dashboard
  - User Management
  - Health Monitoring
  - Activity Logs
  - OpenMRS Configuration
  - Integration Logs
  - System Logs
- Semua request menggunakan `{{base_url}}` dan `{{token}}`
- Tidak ada credential nyata dalam collection

---

## 15. Fitur yang Belum Selesai / Catatan Keamanan Transparan

| Aspek / Fitur | Status | Catatan Transparansi & Rekomendasi |
|---------------|--------|------------------------------------|
| Suspend/deactivate user | Not implemented | Schema `users` tidak memiliki kolom `is_active`/`suspended_at`; perlu migrasi kolom dan spesifikasi flow bisnis lebih lanjut jika akun ingin dapat dibekukan. |
| Admin Web Dashboard (Blade) | Not implemented | Project berfokus pada REST API untuk aplikasi mobile Android. View Blade yang ada hanya untuk dev preview. Frontend web terpisah disarankan menggunakan SPA/dashboard modern. |
| Delete/archive log entries | Not implemented | Log audit (`activity_logs`) dan `integration_logs` tidak menyediakan endpoint delete untuk menjamin integritas audit trail. Jika log membesar, disarankan job scheduled pruning dengan kebijakan retensi formal. |
| OpenMRS clinical data sync | Not implemented | Endpoint saat ini hanya mengelola konfigurasi dan test connection. Sinkronisasi data klinis 2 arah memerlukan consent pasien dan spesifikasi mapping FHIR/OpenMRS lebih lanjut. |
| Scheduled task monitoring | Not implemented | Tidak ada scheduler daemon background yang terdaftar untuk diinspeksi secara real-time via API. |
| DNS Rebinding Edge Case | Mitigated (Software) | `OpenMrsUrlValidator` me-resolve DNS sebelum koneksi dan menolak IP internal. Namun, untuk proteksi level infrastruktur di production, disarankan egress network policy / proxy firewall pada level server/container. |
| Log File Concurrency | Handled (Read-only) | `AdminSystemLogController` membaca `storage/logs/laravel.log` menggunakan `fseek` dari belakang (read-only chunks). Jika file log mengalami rotasi saat proses baca, request aman karena handle file dibuka independen. |

---

## 16. Catatan Integrasi Frontend & Rate Limiting

### Alur Login Admin

1. Frontend memanggil `POST /api/login` dengan email/password biasa.
2. Response menyertakan `user.role.role_name`.
3. Jika `role_name === 'ADMIN'`, frontend dapat mengaktifkan rute navigasi admin.
4. **Token tidak membawa informasi role** — otorisasi dilakukan penuh di server.

### Endpoint Authentication

Semua endpoint admin memerlukan header:
```
Authorization: Bearer <token>
```

### Error Responses

| HTTP | Kondisi |
|------|---------|
| 401 | Token tidak ada atau tidak valid |
| 403 | Token valid tapi role bukan ADMIN |
| 404 | Resource tidak ditemukan |
| 422 | Validasi gagal / SSRF rejection |
| 429 | Too Many Requests (Rate limit terlampaui) |
| 503 | OpenMRS tidak dapat dijangkau / connection timeout |

### Rate Limiting Khusus Admin

Endpoint admin dilengkapi rate limiter terdedikasi di `AppServiceProvider` tanpa mengganggu endpoint user:
- `POST /api/admin/openmrs/test-connection`: **10 request/menit** (`throttle:admin-test-connection`)
- `GET /api/admin/system-logs`: **30 request/menit** (`throttle:admin-system-logs`)
- `GET /api/admin/dashboard`: **60 request/menit** (`throttle:admin-dashboard`)
