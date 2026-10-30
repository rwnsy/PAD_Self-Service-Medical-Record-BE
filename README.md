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

`POST /api/register` returns `202`; it creates a pending user and sends a six-digit code. Send `email` and `code` to `/api/email/verify` before login. Password confirmation is checked when the Android client sends `password_confirmation`; it is never stored. Codes are hashed at rest, expire after 10 minutes, may only be used once, allow five checks, and have a 60-second resend cooldown.

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

## Self Assessment API

All endpoints below require `Authorization: Bearer <sanctum-token>`. A client never sends `user_id`; Laravel assigns the authenticated user as the record owner.

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/health-records` | Create a measurement and server-side self-assessment |
| GET | `/api/health-records` | Paginated history, newest `recorded_at` first |
| GET | `/api/health-records/{healthRecordId}` | Retrieve an owned record |
| PUT/PATCH | `/api/health-records/{healthRecordId}` | Replace/update an owned record and recalculate assessment |
| DELETE | `/api/health-records/{healthRecordId}` | Delete an owned record |

BMI-only request:

```json
{
  "height": 170,
  "weight": 65,
  "recorded_at": "2026-10-20T08:00:00+07:00"
}
```

Blood-pressure-only request:

```json
{
  "systolic": 120,
  "diastolic": 75,
  "recorded_at": "2026-10-20T08:00:00+07:00"
}
```

Height is in centimetres and weight is in kilograms. Laravel calculates `bmi = weight / (height_in_metres ^ 2)` and rounds it to two decimal places before saving. Height must be 50–300 cm, weight 2–500 kg, systolic 50–300 mmHg, diastolic 30–200 mmHg, systolic must exceed diastolic, and `recorded_at` cannot be in the future.

Each request must contain either both `height` and `weight`, or both `systolic` and `diastolic`; both groups may also be sent together for compatibility. Missing metrics are stored as `null`, never as zero. Height is in centimetres and weight is in kilograms. Laravel calculates `bmi = weight / (height_in_metres ^ 2)` and rounds it to two decimal places. Height must be 50-300 cm, weight 2-500 kg, systolic 50-300 mmHg, diastolic 30-200 mmHg, systolic must exceed diastolic, and `recorded_at` cannot be in the future.

The response uses the standard envelope and includes the calculated `bmi`, `warning_status`, and non-diagnostic `warning_message`:

```json
{
  "success": true,
  "message": "Health record created successfully.",
  "data": {
    "health_record_id": 1,
    "bmi": "22.49",
    "bmi_category": "NORMAL",
    "warning_status": "NORMAL",
    "warning_message": "No screening indicator was triggered by this single measurement. This self-assessment is not a medical diagnosis."
  }
}
```

`bmi_category` is `UNDERWEIGHT` (<18.5), `NORMAL` (18.5-<25), `OVERWEIGHT` (25-<30), or `OBESITY` (>=30), and is `null` for a blood-pressure-only record. `warning_status` is a screening aid only. `ATTENTION` is set for adult BMI below 18.5 or at least 25, or blood-pressure readings at least 130 systolic or 80 diastolic. `URGENT` is set only when systolic is above 180 or diastolic is above 120; the message tells the user to repeat the measurement and seek urgent medical advice, particularly with concerning symptoms. The thresholds follow adult BMI screening references from the [World Health Organization](https://www.who.int/en/news-room/fact-sheets/detail/obesity-and-overweight) and blood-pressure categories from the [American Heart Association](https://www.heart.org/en/health-topics/high-blood-pressure/understanding-blood-pressure-readings). They do not diagnose a condition and are not a substitute for a healthcare professional.

History supports optional inclusive date filters and pagination:

```text
GET /api/health-records?recorded_from=2026-10-01&recorded_to=2026-10-31&per_page=20
```

Only the owner can view, update, or delete a record; another user's record returns `404`. This stage deliberately performs no OpenMRS synchronization and writes no integration log for self-assessment data. Existing integration tables remain reserved for a future, explicitly designed integration flow.

`PATCH` (and compatible `PUT`) accepts only values being changed. Omitted measurements are retained and BMI/screening output is recalculated from the resulting record. Invalid data returns `422` with the standard JSON `errors` object; requests without a token return `401`.

## Profile API

`GET /api/me` remains the endpoint for the authenticated user's profile. `PATCH /api/profile` and `PUT /api/profile` update that same user and require `auth:sanctum`. Allowed fields are `name`, `email`, `nik`, `gender` (`MALE` or `FEMALE`), and `date_of_birth` (`YYYY-MM-DD`). `role_id`, password, and access-control fields are ignored.

```json
{
  "name": "Siti Aminah",
  "nik": "3201010101010001",
  "gender": "FEMALE",
  "date_of_birth": "1999-01-01"
}
```

NIK is encrypted at rest and omitted completely from API responses. It must contain 16 digits; a keyed hash is stored for newly supplied NIK values so duplicates can be rejected without querying encrypted ciphertext. A profile response uses the standard envelope and includes safe fields such as `user_id`, `name`, `email`, `gender`, and `date_of_birth`.

Changing `email` sets `email_verified_at` to `null`, marks verification as pending, and sends the existing verification code to the new address. It never treats the new email as verified automatically.

## Tests

Tests use PostgreSQL transactions and never use SQLite. Configure an existing PostgreSQL test connection in `.env.testing` (or your test environment) before running them, migrate that database, then run:

```powershell
php artisan migrate --env=testing
php artisan test --compact
```

Do not point test configuration to a database containing data you need to keep.
