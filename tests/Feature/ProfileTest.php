<?php

use App\Mail\VerificationCodeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function profileUser(array $attributes = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attributes);
}

test('an existing user without optional profile fields can retrieve their own profile', function () {
    $user = profileUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $user->user_id)
        ->assertJsonPath('data.gender', null)
        ->assertJsonPath('data.date_of_birth', null)
        ->assertJsonMissingPath('data.nik');
});

test('an authenticated user can complete profile data without changing their role', function () {
    $user = profileUser();
    $adminRole = Role::query()->firstOrCreate(['role_name' => 'ADMIN']);
    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/profile', [
        'name' => 'Siti Aminah',
        'nik' => '3201010101010001',
        'gender' => 'female',
        'date_of_birth' => '1999-01-01',
        'role_id' => $adminRole->role_id,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Siti Aminah')
        ->assertJsonPath('data.gender', 'FEMALE')
        ->assertJsonPath('data.date_of_birth', '1999-01-01')
        ->assertJsonMissingPath('data.nik');

    $this->assertDatabaseHas('users', [
        'user_id' => $user->user_id,
        'role_id' => $user->role_id,
        'gender' => 'FEMALE',
        'nik_hash' => User::hashNik('3201010101010001'),
    ]);
});

test('an email change requires verification and sends a verification code', function () {
    Mail::fake();
    $user = profileUser([
        'email' => 'old-profile@example.com',
        'email_verified_at' => now(),
        'email_verification_pending' => false,
    ]);
    Sanctum::actingAs($user);

    $this->putJson('/api/profile', ['email' => 'new-profile@example.com'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'new-profile@example.com')
        ->assertJsonPath('data.email_verification_pending', true);

    Mail::assertSent(VerificationCodeMail::class, 'new-profile@example.com');

    expect($user->refresh()->email_verified_at)->toBeNull();
    expect($user->email_verification_pending)->toBeTrue();
});

test('a NIK cannot be reused by another account', function () {
    $firstUser = profileUser([
        'nik' => '3201010101010001',
        'nik_hash' => User::hashNik('3201010101010001'),
    ]);
    $secondUser = profileUser();
    Sanctum::actingAs($secondUser);

    $this->patchJson('/api/profile', ['nik' => '3201010101010001'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nik']);

    expect($firstUser->nik)->toBe('3201010101010001');
});

test('profile updates require authentication', function () {
    $this->patchJson('/api/profile', ['name' => 'Unauthenticated User'])
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});
