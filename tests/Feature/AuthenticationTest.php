<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

it('creates a user with the default USER role and returns 201', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Siti Aminah',
        'email' => 'siti@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '081234567890',
        'nik' => '3201010101010001',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'siti@example.com')
        ->assertJsonPath('data.role.role_name', 'USER')
        ->assertJsonMissingPath('data.phone')
        ->assertJsonMissingPath('data.nik');

    $this->assertDatabaseHas('users', ['email' => 'siti@example.com']);

    $user = User::query()->where('email', 'siti@example.com')->firstOrFail();

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
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.role.role_name', 'USER');

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
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});
