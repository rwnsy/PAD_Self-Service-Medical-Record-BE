<?php

use App\Mail\VerificationCodeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTransactions::class);

it('starts registration and sends an email verification code', function () {
    Mail::fake();

    $response = $this->postJson('/api/register', [
        'name' => 'Siti Aminah',
        'email' => 'siti@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '081234567890',
        'nik' => '3201010101010001',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'siti@example.com')
        ->assertJsonMissingPath('data.code');

    Mail::assertSent(VerificationCodeMail::class, 'siti@example.com');

    $user = User::query()->where('email', 'siti@example.com')->firstOrFail();

    expect($user->email_verification_pending)->toBeTrue();
    expect(Hash::check('password123', $user->password))->toBeTrue();
});

it('returns a Sanctum bearer token for valid credentials', function () {
    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($userRole, 'role')->create([
        'email' => 'login@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.user_id', $user->user_id);
});

it('returns 401 from me when no bearer token is provided', function () {
    $response = $this->getJson('/api/me');

    $response
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('revokes the current Sanctum token when logging out', function () {
    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($userRole, 'role')->create();
    $accessToken = $user->createToken('test-token');

    $response = $this->withToken($accessToken->plainTextToken)->postJson('/api/logout');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', null);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $accessToken->accessToken->getKey(),
    ]);
});

it('ignores an ADMIN role supplied during registration', function () {
    $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $adminRole = Role::query()->firstOrCreate(['role_name' => 'ADMIN']);

    $response = $this->postJson('/api/register', [
        'name' => 'Regular User',
        'email' => 'regular@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $adminRole->role_id,
    ]);

    $response
        ->assertStatus(202)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('users', [
        'email' => 'regular@example.com',
        'role_id' => $userRole->role_id,
    ]);
});

it('returns 422 with validation errors for an incomplete registration payload', function () {
    $response = $this->postJson('/api/register', []);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['email', 'password']);
});

it('accepts registration without password confirmation when the client does not send it', function () {
    Mail::fake();

    $this->postJson('/api/register', [
        'name' => 'No Confirmation User',
        'email' => 'no-confirmation@example.com',
        'password' => 'password123',
    ])->assertAccepted();
});

it('allows registration without a name so the user can complete their profile later', function () {
    Mail::fake();

    $response = $this->postJson('/api/register', [
        'email' => 'no-name@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'no-name@example.com');

    $user = User::query()->where('email', 'no-name@example.com')->firstOrFail();

    expect($user->name)->toBeNull();
    expect($user->email_verification_pending)->toBeTrue();
});
