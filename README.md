# Self Medical Record API

Laravel 13 REST API backend for the Android Self Medical Record client. This initial foundation uses PHP 8.4.1, PostgreSQL, and Laravel Sanctum bearer tokens.

## Setup

1. Select PHP 8.4.1 in Laragon (or ensure `php -v` reports 8.4.1).
2. Copy `.env.example` to `.env` if needed, then configure the existing PostgreSQL connection. Do not use SQLite or MySQL.
3. Install PHP dependencies and initialize the schema and roles:

   ```powershell
   composer install
   php artisan key:generate
   php artisan migrate --seed
   ```

4. Run the API locally:

   ```powershell
   php artisan serve
   ```

## Authentication endpoints

| Method | Endpoint | Authentication |
| --- | --- | --- |
| POST | `/api/register` | No; always assigns `USER` |
| POST | `/api/login` | No; returns a Sanctum bearer token |
| POST | `/api/logout` | `auth:sanctum` |
| GET | `/api/me` | `auth:sanctum` |

Protected admin routes can use `auth:sanctum` together with `role:ADMIN`. User-supplied `role_id` is ignored during registration.

## Tests

Tests use PostgreSQL transactions and never use SQLite. Configure an existing PostgreSQL test connection in `.env.testing` (or your test environment) before running them, migrate that database, then run:

```powershell
php artisan migrate --env=testing
php artisan test --compact
```

Do not point test configuration to a database containing data you need to keep.
