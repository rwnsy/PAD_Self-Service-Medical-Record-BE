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
| POST | `/api/email/verify` | No; verifies a one-time email code |
| POST | `/api/email/resend-code` | No; 60-second resend cooldown |
| POST | `/api/login` | No; returns a Sanctum bearer token |
| POST | `/api/password/forgot` | No; sends a reset code without revealing account existence |
| POST | `/api/password/verify-code` | No; exchanges a reset code for a short-lived reset token |
| POST | `/api/password/reset` | No; resets the password using that token |
| POST | `/api/auth/google` | No; verifies a Google ID token and returns a Sanctum bearer token |
| POST | `/api/logout` | `auth:sanctum` |
| GET | `/api/me` | `auth:sanctum` |

`POST /api/register` now returns `202`; it creates a pending user and sends a six-digit code. Send `email` and `code` to `/api/email/verify` before login. Codes are hashed at rest, expire after 10 minutes, may only be used once, allow five checks, and have a 60-second resend cooldown.

Protected admin routes can use `auth:sanctum` together with `role:ADMIN`. User-supplied `role_id` is ignored during registration.

## Email configuration

The current local mailer is `log`. Before testing a real email flow, set the following existing `.env` variables for an SMTP provider; do not commit the password:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Self Medical Record"
```

## Google sign-in configuration

Create a Google OAuth **Web application** client ID for the server-side ID-token audience. Set only its client ID in Laravel:

```dotenv
GOOGLE_ID_TOKEN_AUDIENCE=your-web-client-id.apps.googleusercontent.com
```

In Android Credential Manager, request a Google ID token using that same server client ID. Send the returned ID token as `id_token` to `POST /api/auth/google`. Laravel sends it to Google token-info for signature validation, then independently validates its issuer, audience, expiry, subject, and verified-email claim. No Google client secret belongs in Android or this API for this flow.

Google users are created as `USER`, with a nullable local password. An existing local account is linked only when both its local email and the Google claim have already been verified; otherwise the API returns `409` and must be linked through a future authenticated account-linking flow.

## Tests

Tests use PostgreSQL transactions and never use SQLite. Configure an existing PostgreSQL test connection in `.env.testing` (or your test environment) before running them, migrate that database, then run:

```powershell
php artisan migrate --env=testing
php artisan test --compact
```

Do not point test configuration to a database containing data you need to keep.
