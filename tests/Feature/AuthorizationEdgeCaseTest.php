<?php

use App\Models\HealthRecord;
use App\Models\PersonalMedicalRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function edgeCaseUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create(array_merge([
        'email_verified_at' => now(),
        'email_verification_pending' => false,
    ], $attrs));
}

// ============================================================
// 1. Authentication Edge Cases
// ============================================================

test('login with wrong password returns 401', function () {
    $user = edgeCaseUser([
        'email' => 'wrongpass@example.com',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'wrongpass@example.com',
        'password' => 'incorrect-pass',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email or password is incorrect.');
});

test('login with non-existent email returns 401', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'secret123',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email or password is incorrect.');
});

test('registration rejects duplicate email', function () {
    edgeCaseUser(['email' => 'duplicate@example.com']);

    $response = $this->postJson('/api/register', [
        'email' => 'duplicate@example.com',
        'password' => 'password123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('registration rejects duplicate NIK hash', function () {
    edgeCaseUser([
        'nik' => '3201010101010001',
        'nik_hash' => User::hashNik('3201010101010001'),
    ]);

    $response = $this->postJson('/api/register', [
        'email' => 'newuser@example.com',
        'password' => 'password123',
        'nik' => '3201010101010001',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['nik']);
});

test('revoked or invalid token cannot access protected endpoints', function () {
    $user = edgeCaseUser();
    $token = $user->createToken('test-token');
    $plainText = $token->plainTextToken;

    // First request with valid token
    $this->withToken($plainText)->getJson('/api/me')->assertOk();

    // Revoke token in database
    $token->accessToken->delete();

    // Reset authentication guard in test environment to avoid cached user
    $this->app['auth']->forgetGuards();

    // Second request with revoked token must fail
    $this->withToken($plainText)->getJson('/api/me')->assertUnauthorized();
});

test('logout deletes current access token and renders it unusable', function () {
    $user = edgeCaseUser();
    $token = $user->createToken('android-api');
    $plainText = $token->plainTextToken;

    $this->withToken($plainText)->postJson('/api/logout')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $token->accessToken->getKey(),
    ]);

    // Reset authentication guard in test environment
    $this->app['auth']->forgetGuards();

    // Request after logout must be unauthorized
    $this->withToken($plainText)->getJson('/api/me')->assertUnauthorized();
});

// ============================================================
// 2. Authorization / Ownership Isolation (IDOR / BOLA)
// ============================================================

test('user cannot view health record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = HealthRecord::factory()->for($owner, 'user')->create([
        'height' => 170,
        'weight' => 65,
        'bmi' => 22.49,
        'recorded_at' => now(),
    ]);

    Sanctum::actingAs($attacker);

    $this->getJson("/api/health-records/{$record->health_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

test('user cannot update health record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = HealthRecord::factory()->for($owner, 'user')->create([
        'height' => 170,
        'weight' => 65,
        'bmi' => 22.49,
        'recorded_at' => now(),
    ]);

    Sanctum::actingAs($attacker);

    $this->putJson("/api/health-records/{$record->health_record_id}", [
        'height' => 180,
        'weight' => 80,
    ])->assertNotFound()
        ->assertJsonPath('success', false);

    expect($record->fresh()->height)->toBe('170.00');
});

test('user cannot delete health record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = HealthRecord::factory()->for($owner, 'user')->create([
        'height' => 170,
        'weight' => 65,
        'bmi' => 22.49,
        'recorded_at' => now(),
    ]);

    Sanctum::actingAs($attacker);

    $this->deleteJson("/api/health-records/{$record->health_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);

    expect(HealthRecord::query()->whereKey($record->health_record_id)->exists())->toBeTrue();
});

test('user cannot view personal medical record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = PersonalMedicalRecord::factory()->for($owner, 'user')->create([
        'facility_name' => 'RSUD Siloam',
        'complaint' => 'Demam tinggi',
        'visit_date' => '2026-10-01',
    ]);

    Sanctum::actingAs($attacker);

    $this->getJson("/api/personal-medical-records/{$record->personal_medical_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

test('user cannot update personal medical record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = PersonalMedicalRecord::factory()->for($owner, 'user')->create([
        'facility_name' => 'RSUD Siloam',
        'complaint' => 'Demam tinggi',
        'visit_date' => '2026-10-01',
    ]);

    Sanctum::actingAs($attacker);

    $this->putJson("/api/personal-medical-records/{$record->personal_medical_record_id}", [
        'facility_name' => 'Klinik Hacked',
    ])->assertNotFound()
        ->assertJsonPath('success', false);

    expect($record->fresh()->facility_name)->toBe('RSUD Siloam');
});

test('user cannot delete personal medical record of another user', function () {
    $owner = edgeCaseUser();
    $attacker = edgeCaseUser();

    $record = PersonalMedicalRecord::factory()->for($owner, 'user')->create([
        'facility_name' => 'RSUD Siloam',
        'complaint' => 'Demam tinggi',
        'visit_date' => '2026-10-01',
    ]);

    Sanctum::actingAs($attacker);

    $this->deleteJson("/api/personal-medical-records/{$record->personal_medical_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);

    expect(PersonalMedicalRecord::query()->whereKey($record->personal_medical_record_id)->exists())->toBeTrue();
});

// ============================================================
// 3. Validation Edge Cases
// ============================================================

test('health record creation fails when systolic is less than or equal to diastolic', function () {
    $user = edgeCaseUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'systolic' => 80,
        'diastolic' => 120,
        'recorded_at' => '2026-10-25 08:00:00',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['systolic']);
});

test('personal medical record rejects future visit date', function () {
    $user = edgeCaseUser();
    Sanctum::actingAs($user);

    $futureDate = now()->addDays(7)->format('Y-m-d');

    $response = $this->postJson('/api/personal-medical-records', [
        'visit_date' => $futureDate,
        'facility_name' => 'Klinik Masa Depan',
        'complaint' => 'Keluhan masa depan',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['visit_date']);
});
