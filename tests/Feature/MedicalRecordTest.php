<?php

use App\Models\OpenmrsConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function medicalRecordUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attrs);
}

test('medical records endpoint requires authentication', function () {
    $this->getJson('/api/medical-records')
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('medical records returns 503 when no active OpenMRS config exists', function () {
    $user = medicalRecordUser();
    Sanctum::actingAs($user);

    // Pastikan tidak ada config aktif
    OpenmrsConfig::query()->where('is_active', true)->update(['is_active' => false]);

    $this->getJson('/api/medical-records')
        ->assertStatus(503)
        ->assertJsonPath('success', false);
});

test('medical records returns empty data when user has no NIK', function () {
    $user = medicalRecordUser(); // factory tidak set NIK
    Sanctum::actingAs($user);

    // Buat config aktif
    OpenmrsConfig::create([
        'endpoint_url' => 'http://test-openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'test']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    // Mock: pastikan tidak ada HTTP request ke OpenMRS
    Http::fake();

    $this->getJson('/api/medical-records')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.meta.patient_found', false)
        ->assertJsonPath('data.data', []);
});

test('medical records returns 503 when OpenMRS connection fails', function () {
    $user = medicalRecordUser(['nik' => '3201010101010001']);
    Sanctum::actingAs($user);

    OpenmrsConfig::create([
        'endpoint_url' => 'http://unreachable-openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'test']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    Http::fake([
        'unreachable-openmrs.example.com/*' => Http::response([], 500),
    ]);

    $this->getJson('/api/medical-records')
        ->assertStatus(503)
        ->assertJsonPath('success', false);
});

test('medical records returns formatted encounters from OpenMRS', function () {
    $user = medicalRecordUser(['nik' => '3201010101010002']);
    Sanctum::actingAs($user);

    OpenmrsConfig::create([
        'endpoint_url' => 'http://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response([
            'results' => [['uuid' => 'patient-uuid-123']],
        ]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response([
            'results' => [
                [
                    'uuid' => 'encounter-uuid-1',
                    'encounterType' => ['display' => 'Clinic Visit'],
                    'encounterDatetime' => '2026-10-15T09:00:00.000+0700',
                    'location' => ['display' => 'Klinik Utama'],
                    'obs' => [
                        [
                            'concept' => ['display' => 'Chief Complaint'],
                            'value' => ['display' => 'Sakit kepala'],
                        ],
                    ],
                    'orders' => [],
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/medical-records');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.meta.patient_found', true)
        ->assertJsonPath('data.meta.source', 'openmrs')
        ->assertJsonPath('data.data.0.encounter_uuid', 'encounter-uuid-1')
        ->assertJsonPath('data.data.0.facility_name', 'Klinik Utama')
        ->assertJsonPath('data.data.0.location', 'Klinik Utama')
        ->assertJsonPath('data.data.0.complaint', 'Sakit kepala')
        ->assertJsonPath('data.data.0.visit_date', '2026-10-15');
});

test('medical records supports pagination query parameters', function () {
    $user = medicalRecordUser(['nik' => '3201010101010003']);
    Sanctum::actingAs($user);

    OpenmrsConfig::create([
        'endpoint_url' => 'http://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response([
            'results' => [['uuid' => 'patient-uuid-456']],
        ]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response([
            'results' => [],
        ]),
    ]);

    $response = $this->getJson('/api/medical-records?page=2&per_page=5');

    $response->assertOk()
        ->assertJsonPath('data.meta.page', 2)
        ->assertJsonPath('data.meta.per_page', 5);
});

test('medical records does not expose OpenMRS credentials', function () {
    $user = medicalRecordUser();
    Sanctum::actingAs($user);

    OpenmrsConfig::create([
        'endpoint_url' => 'http://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'secret-password']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    $response = $this->getJson('/api/medical-records');
    $body = $response->getContent();

    expect($body)->not->toContain('secret-password');
    expect($body)->not->toContain('Admin123');
});
