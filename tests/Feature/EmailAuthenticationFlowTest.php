<?php

use App\Mail\VerificationCodeMail;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTransactions::class);

it('rejects registration when the email is already registered', function () {
    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    User::factory()->for($userRole, 'role')->create(['email' => 'existing@example.com']);

    $response = $this->postJson('/api/register', [
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('rejects a registration with a mismatched password confirmation', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different123',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('verifies an email with a valid one-time code', function () {
    Mail::fake();

    $this->postJson('/api/register', [
        'name' => 'Verify User',
        'email' => 'verify@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertAccepted();

    $mail = Mail::sent(VerificationCodeMail::class)->sole();

    $response = $this->postJson('/api/email/verify', [
        'email' => 'verify@example.com',
        'code' => $mail->code,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true);

    $user = User::query()->where('email', 'verify@example.com')->firstOrFail();
    $verificationCode = VerificationCode::query()->where('user_id', $user->user_id)->firstOrFail();

    expect($user->email_verification_pending)->toBeFalse();
    expect($user->email_verified_at)->not->toBeNull();
    expect($verificationCode->used_at)->not->toBeNull();

    $this->postJson('/api/email/verify', [
        'email' => 'verify@example.com',
        'code' => $mail->code,
    ])->assertUnprocessable();
});

it('rejects an expired email verification code', function () {
    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($userRole, 'role')->create([
        'email' => 'expired@example.com',
        'email_verification_pending' => true,
        'email_verified_at' => null,
    ]);
    VerificationCode::factory()->for($user, 'user')->create([
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->postJson('/api/email/verify', [
        'email' => $user->email,
        'code' => '123456',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('limits email verification code resends', function () {
    Mail::fake();

    $this->postJson('/api/register', [
        'name' => 'Resend User',
        'email' => 'resend@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertAccepted();

    $response = $this->postJson('/api/email/resend-code', [
        'email' => 'resend@example.com',
    ]);

    $response
        ->assertTooManyRequests()
        ->assertJsonPath('success', false);

    expect($response->json('data.retry_after'))->toBeGreaterThan(0);
});

it('does not allow a pending email-verification account to log in', function () {
    Mail::fake();

    $this->postJson('/api/register', [
        'name' => 'Pending User',
        'email' => 'pending@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertAccepted();

    $this->postJson('/api/login', [
        'email' => 'pending@example.com',
        'password' => 'password123',
    ])->assertForbidden();
});

it('resets a password through a one-time email code', function () {
    Mail::fake();

    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($userRole, 'role')->create([
        'email' => 'reset@example.com',
        'password' => Hash::make('old-password'),
    ]);

    $this->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('success', true);

    $mail = Mail::sent(VerificationCodeMail::class)->sole();

    $verificationResponse = $this->postJson('/api/password/verify-code', [
        'email' => $user->email,
        'code' => $mail->code,
    ]);

    $verificationResponse
        ->assertOk()
        ->assertJsonPath('success', true);

    $resetToken = $verificationResponse->json('data.reset_token');

    $this->postJson('/api/password/verify-code', [
        'email' => $user->email,
        'code' => $mail->code,
    ])->assertUnprocessable();

    $this->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $resetToken,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();

    $this->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $resetToken,
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertUnprocessable();
});

it('returns the same forgot-password response for an unknown email', function () {
    Mail::fake();

    $response = $this->postJson('/api/password/forgot', ['email' => 'unknown@example.com']);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'If the email is registered, a password reset code has been sent.');

    Mail::assertNothingSent();
});

it('rejects an invalid Google ID token', function () {
    config(['services.google.id_token_audience' => 'server-client-id.apps.googleusercontent.com']);
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response([], 400),
    ]);

    $response = $this->postJson('/api/auth/google', ['id_token' => 'invalid-token']);

    $response
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

it('rejects a Google ID token with the wrong audience', function () {
    config(['services.google.id_token_audience' => 'server-client-id.apps.googleusercontent.com']);
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response([
            'sub' => 'google-subject-1',
            'email' => 'google@example.com',
            'email_verified' => 'true',
            'aud' => 'wrong-client-id.apps.googleusercontent.com',
            'iss' => 'https://accounts.google.com',
            'exp' => (string) now()->addHour()->getTimestamp(),
        ]),
    ]);

    $this->postJson('/api/auth/google', ['id_token' => 'wrong-audience-token'])
        ->assertUnauthorized();
});

it('creates a USER account for a verified new Google identity', function () {
    config(['services.google.id_token_audience' => 'server-client-id.apps.googleusercontent.com']);
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response([
            'sub' => 'google-subject-2',
            'email' => 'new-google@example.com',
            'email_verified' => 'true',
            'aud' => 'server-client-id.apps.googleusercontent.com',
            'iss' => 'https://accounts.google.com',
            'exp' => (string) now()->addHour()->getTimestamp(),
            'name' => 'New Google User',
        ]),
    ]);

    $response = $this->postJson('/api/auth/google', ['id_token' => 'valid-google-token']);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.role.role_name', 'USER');

    $user = User::query()->where('email', 'new-google@example.com')->firstOrFail();

    expect($user->password)->toBeNull();
    expect($user->google_sub)->toBe('google-subject-2');
    expect($user->email_verified_at)->not->toBeNull();
});
