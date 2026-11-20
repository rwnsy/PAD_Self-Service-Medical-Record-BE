<?php

use App\Models\IntegrationLog;
use App\Models\OpenmrsConfig;
use App\Models\PersonalMedicalRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

// Test tidak boleh menyentuh server OpenMRS sungguhan: blok request tak ter-fake, matikan config aktif di DB,
// dan netralkan kebijakan SSRF dari .env lokal agar hasil test deterministik.
beforeEach(function () {
    Http::preventStrayRequests();
    OpenmrsConfig::query()->update(['is_active' => false]);
    config([
        'services.openmrs.allowed_hosts' => [],
        'services.openmrs.enforce_https' => false,
        'services.openmrs.allow_local_network' => true,
        'services.openmrs.strict_ssrf' => false,
    ]);
});

function medicalRecordUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attrs);
}

function openMrsTestConfig(string $url = 'http://openmrs.example.com', string $credential = '{"username":"admin","password":"secret-password"}'): OpenmrsConfig
{
    return OpenmrsConfig::create([
        'endpoint_url' => $url,
        'credential' => $credential,
        'is_active' => true,
        'updated_by' => null,
    ]);
}

/**
 * @return array<string, string>
 */
function openMrsQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @return array<string, mixed>
 */
function openMrsPatient(string $uuid, string $identifier, bool $voided = false): array
{
    return ['uuid' => $uuid, 'identifiers' => [['identifier' => $identifier, 'voided' => $voided]]];
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

    Http::assertNothingSent();
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
            'results' => [openMrsPatient('patient-uuid-123', '3201010101010002')],
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
        ->assertJsonPath('data.data.0.source', 'OPENMRS')
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
            'results' => [openMrsPatient('patient-uuid-456', '3201010101010003')],
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

test('medical records reports patient not found when OpenMRS has no match', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010004']));
    openMrsTestConfig();

    Http::fake(['openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => []])]);

    $this->getJson('/api/medical-records')
        ->assertOk()
        ->assertJsonPath('data.meta.patient_found', false)
        ->assertJsonPath('data.meta.reason', 'PATIENT_NOT_FOUND')
        ->assertJsonPath('data.data', []);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/encounter'));
});

test('medical records ignores patients whose identifier is not an exact NIK match', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010005']));
    openMrsTestConfig();

    // Server mengabaikan filter identifier: mengembalikan pasien lain, pasien dengan identifier voided, dan tanpa identifier.
    Http::fake(['openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [
        openMrsPatient('other-patient', '3201010101019999'),
        openMrsPatient('voided-patient', '3201010101010005', voided: true),
        ['uuid' => 'no-identifier-patient'],
    ]])]);

    $this->getJson('/api/medical-records')
        ->assertOk()
        ->assertJsonPath('data.meta.patient_found', false)
        ->assertJsonPath('data.data', []);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/encounter'));
});

test('medical records does not pick a patient when the identifier is ambiguous', function () {
    $user = medicalRecordUser(['nik' => '3201010101010006']);
    Sanctum::actingAs($user);
    openMrsTestConfig();

    Http::fake(['openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [
        openMrsPatient('patient-a', '3201010101010006'),
        openMrsPatient('patient-b', '3201010101010006'),
    ]])]);

    $this->getJson('/api/medical-records')
        ->assertOk()
        ->assertJsonPath('data.meta.patient_found', false)
        ->assertJsonPath('data.meta.reason', 'PATIENT_AMBIGUOUS')
        ->assertJsonPath('data.data', []);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/encounter'));
    expect(IntegrationLog::query()->where('user_id', $user->user_id)->where('status', 'AMBIGUOUS')->exists())->toBeTrue();
});

test('medical records maps OpenMRS HTTP errors to a safe 503', function (int $status) {
    $user = medicalRecordUser(['nik' => '3201010101010007']);
    Sanctum::actingAs($user);
    openMrsTestConfig();

    Http::fake(['openmrs.example.com/*' => Http::response(['error' => ['message' => 'secret-password 3201010101010007'], 'stack' => 'trace'], $status)]);

    $response = $this->getJson('/api/medical-records')
        ->assertStatus(503)
        ->assertJsonPath('success', false);

    expect($response->getContent())->not->toContain('secret-password')->not->toContain('3201010101010007')->not->toContain('trace');
    expect(IntegrationLog::query()->where('user_id', $user->user_id)->value('error_message'))->toBe('http_'.$status);
})->with([401, 403, 404, 429, 500, 503]);

test('medical records returns 503 when OpenMRS times out', function () {
    $user = medicalRecordUser(['nik' => '3201010101010008']);
    Sanctum::actingAs($user);
    openMrsTestConfig();

    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $this->getJson('/api/medical-records')->assertStatus(503)->assertJsonPath('success', false);

    expect(IntegrationLog::query()->where('user_id', $user->user_id)->value('error_message'))->toBe('connection_failed');
});

test('medical records returns 503 when OpenMRS returns invalid JSON', function () {
    $user = medicalRecordUser(['nik' => '3201010101010009']);
    Sanctum::actingAs($user);
    openMrsTestConfig();

    Http::fake(['openmrs.example.com/*' => Http::response('<html>login</html>', 200)]);

    $this->getJson('/api/medical-records')->assertStatus(503);

    expect(IntegrationLog::query()->where('user_id', $user->user_id)->value('error_message'))->toBe('invalid_response');
});

test('medical records returns 503 when stored credential format is invalid', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010010']));
    openMrsTestConfig(credential: 'not-json');
    Http::fake();

    $this->getJson('/api/medical-records')->assertStatus(503);

    Http::assertNothingSent();
});

test('medical records never calls an endpoint rejected by the SSRF policy', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010011']));
    config(['services.openmrs.strict_ssrf' => true]);
    openMrsTestConfig('https://127.0.0.1');
    Http::fake();

    $this->getJson('/api/medical-records')->assertStatus(503);

    Http::assertNothingSent();
});

test('medical records returns an empty list when the patient has no encounters', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010012']));
    openMrsTestConfig();

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [openMrsPatient('patient-uuid-empty', '3201010101010012')]]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response(['results' => []]),
    ]);

    $this->getJson('/api/medical-records')
        ->assertOk()
        ->assertJsonPath('data.meta.patient_found', true)
        ->assertJsonPath('data.meta.has_more', false)
        ->assertJsonPath('data.data', []);
});

test('medical records maps page to OpenMRS startIndex and reports has_more', function () {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010013']));
    openMrsTestConfig();

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [openMrsPatient('patient-uuid-page', '3201010101010013')]]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response([
            'results' => [['uuid' => 'encounter-uuid-2', 'orders' => [['type' => 'testorder'], ['type' => 'drugorder', 'drug' => ['display' => 'Paracetamol 500mg']]]]],
            'links' => [['rel' => 'next', 'uri' => 'http://openmrs.example.com/ws/rest/v1/encounter?startIndex=10']],
        ]),
    ]);

    $this->getJson('/api/medical-records?page=2&per_page=5')
        ->assertOk()
        ->assertJsonPath('data.meta.has_more', true)
        ->assertJsonPath('data.data.0.medications.0.drug', 'Paracetamol 500mg');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/encounter')
        && (int) openMrsQuery($request)['startIndex'] === 5
        && (int) openMrsQuery($request)['limit'] === 5
        && openMrsQuery($request)['patient'] === 'patient-uuid-page');
});

test('medical records only queries OpenMRS with the authenticated user NIK', function () {
    $user = medicalRecordUser(['nik' => '3201010101010014']);
    Sanctum::actingAs($user);
    openMrsTestConfig();

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [openMrsPatient('patient-uuid-own', '3201010101010014')]]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response(['results' => []]),
    ]);

    // Parameter yang mencoba mengganti identitas pasien harus diabaikan.
    $this->getJson('/api/medical-records?identifier=9999999999999999&nik=9999999999999999&patient=evil-uuid&patient_uuid=evil-uuid')
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/patient') && openMrsQuery($request)['identifier'] === '3201010101010014');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/encounter') && openMrsQuery($request)['patient'] === 'patient-uuid-own');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil-uuid') || str_contains($request->url(), '9999999999999999'));
});

test('medical records keeps personal medical records separate from OpenMRS data', function () {
    $user = medicalRecordUser(['nik' => '3201010101010015']);
    Sanctum::actingAs($user);
    openMrsTestConfig();
    $personal = PersonalMedicalRecord::factory()->for($user)->create(['complaint' => 'KELUHAN-MANDIRI']);

    Http::fake([
        'openmrs.example.com/ws/rest/v1/patient*' => Http::response(['results' => [openMrsPatient('patient-uuid-mix', '3201010101010015')]]),
        'openmrs.example.com/ws/rest/v1/encounter*' => Http::response(['results' => [['uuid' => 'encounter-uuid-3']]]),
    ]);

    $clinical = $this->getJson('/api/medical-records')->assertOk();
    expect($clinical->getContent())->not->toContain('KELUHAN-MANDIRI');
    $clinical->assertJsonPath('data.data.0.source', 'OPENMRS');

    $this->getJson('/api/personal-medical-records')
        ->assertOk()
        ->assertJsonPath('data.data.0.source', 'USER_REPORTED')
        ->assertJsonPath('data.data.0.personal_medical_record_id', $personal->personal_medical_record_id);
});

test('medical records endpoint does not support write operations', function (string $method) {
    Sanctum::actingAs(medicalRecordUser(['nik' => '3201010101010016']));
    openMrsTestConfig();
    Http::fake();

    $this->json($method, '/api/medical-records', ['complaint' => 'x'])->assertStatus(405);

    Http::assertNothingSent();
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);
