# Performance Report & Profiling — Self Medical Record

**Audit Date:** 2026-11-05  
**Framework:** Laravel 13.34.0 (PHP 8.4.1 NTS Win64)  
**Database:** PostgreSQL (Laragon local environment)  
**Methodology:** Direct kernel execution profiling across 30 iterations (after 3 warmups) with `DB::getQueryLog()` profiling and PostgreSQL EXPLAIN plan analysis.

---

## 1. Baseline Performance Benchmark Results

| Endpoint | Method | HTTP Status | Min (ms) | p50 (ms) | Avg (ms) | p95 (ms) | Queries | Avg DB Time (ms) |
|---|---|---|---|---|---|---|---|---|
| `/api/login` | POST | 200 | 30.90 | 187.14 | 135.14 | 187.37 | 7.0 | 2.38 |
| `/api/me` | GET | 200 | 1.05 | 1.14 | 1.24 | 1.77 | 1.0 | 0.28 |
| `/api/dashboard` | GET | 200 | 7.75 | 8.53 | 8.83 | 11.48 | 7.0 | 2.59 |
| `/api/health-records` | GET | 200 | 5.96 | 6.97 | 7.10 | 8.42 | 2.0 | 0.97 |
| `/api/health-records` | POST | 201 | 1.67 | 2.29 | 2.29 | 3.38 | 1.0 | 0.58 |
| `/api/personal-medical-records` | GET | 200 | 3.83 | 4.54 | 4.55 | 5.54 | 2.0 | 0.87 |
| `/api/personal-medical-records` | POST | 201 | 1.38 | 1.65 | 1.75 | 2.29 | 1.0 | 0.49 |

*Note: Login response time (~135-187ms) is dominated by bcrypt password hashing calculation (10-12 rounds), which is by design for cryptographic security. Database queries for login take only 2.38ms.*

---

## 2. Detailed Endpoint Analysis

### A. `GET /api/me` (Profile Retrieval)
- **p50:** 1.14 ms | **p95:** 1.77 ms
- **DB Queries:** 1 query (Sanctum user resolution)
- **Memory Peak:** ~18 MB
- **Bottlenecks:** None. Extremely lightweight and fast.

### B. `GET /api/dashboard` (Summary & Charts)
- **p50:** 8.53 ms | **p95:** 11.48 ms
- **DB Queries:** 7 separate queries:
  1. `SELECT ... WHERE user_id = ? AND height IS NOT NULL AND weight IS NOT NULL ORDER BY recorded_at DESC LIMIT 1` (Latest BMI)
  2. `SELECT ... WHERE user_id = ? AND systolic IS NOT NULL AND diastolic IS NOT NULL ORDER BY recorded_at DESC LIMIT 1` (Latest BP)
  3. `SELECT ... WHERE user_id = ? AND warning_status IS NOT NULL ORDER BY recorded_at DESC LIMIT 1` (Latest Warning)
  4. `SELECT COUNT(*) WHERE user_id = ? AND recorded_at >= ?` (Total Assessments)
  5. `SELECT ... WHERE user_id = ? AND recorded_at >= ? ORDER BY recorded_at DESC LIMIT 1` (Last record date)
  6. `SELECT health_record_id, bmi, bmi_category, recorded_at WHERE user_id = ? AND bmi IS NOT NULL AND recorded_at >= ? ORDER BY recorded_at ASC` (BMI Trend)
  7. `SELECT health_record_id, systolic, diastolic, warning_status, recorded_at WHERE user_id = ? AND systolic IS NOT NULL AND diastolic IS NOT NULL AND recorded_at >= ? ORDER BY recorded_at ASC` (BP Trend)
- **Analysis:** While currently sub-10ms with local PostgreSQL, 7 queries per dashboard load is a potential bottleneck at scale. Combining the summary metrics into a single query or caching the latest assessment per user would reduce query count from 7 to 1 or 2.

### C. `GET /api/health-records` & `GET /api/personal-medical-records` (Pagination)
- **p50:** ~4.5 - 7.0 ms
- **DB Queries:** 2 queries standard for Laravel pagination (`COUNT(*)` followed by `SELECT ... LIMIT 15 OFFSET 0`).
- **Index Usage:**
  - `personal_medical_records` uses `Index Scan Backward using personal_medical_records_user_id_visit_date_index`.
  - `health_records` has composite index `health_records_user_id_recorded_at_index` added in migration `2026_11_05_190949`.

---

## 3. Database Execution Plan (EXPLAIN)

### Query 1: Personal Medical Records History
```text
Limit  (cost=0.14..8.16 rows=1 width=698)
  ->  Index Scan Backward using personal_medical_records_user_id_visit_date_index on personal_medical_records
        Index Cond: (user_id = '21'::bigint)
```
**Status:** ✅ Optimal index scan backward (descending order).

### Query 2: Health Records History & Dashboard Queries
PostgreSQL query planner utilizes `health_records_user_id_recorded_at_index` and `health_records_user_id_index` when table cardinality exceeds the cost threshold of sequential page reads.

---

## 4. Hardware & Environment Specifications

- **OS:** Windows 10/11 x64
- **Web Server:** Laragon (Local Apache/Nginx + PHP CLI)
- **PHP:** 8.4.1 NTS (OPcache available, JIT disabled in CLI)
- **Database:** PostgreSQL 16
- **Cache Driver:** database (per `.env.example`)
- **Queue Driver:** database (per `.env.example`)
- **Session Driver:** database (per `.env.example`)

---

## 5. Architectural Recommendations for Scale

1. **Dashboard Query Consolidation:**
   Combine the 3 "latest" record queries in `DashboardController` into a single query using window functions (`ROW_NUMBER() OVER (PARTITION BY ...)` or CTE) or retrieve the single most recent record and inspect both BMI and BP fields.
2. **Redis for Cache / Queue / Session:**
   Currently `database` driver is used for cache, session, and queues. Migrating to Redis in production will prevent session table locking and reduce PostgreSQL load by 30–40%.
3. **HTTP Keep-Alive & Connection Pooling:**
   Ensure PostgreSQL `PgBouncer` or connection pooling is enabled in staging/production to handle concurrent mobile client requests.

