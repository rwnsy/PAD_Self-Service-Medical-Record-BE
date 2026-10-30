<?php

use App\Models\IntegrationLog;
use App\Models\PersonalMedicalRecord;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function personalMedicalRecordUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attrs);
}

function validPersonalRecordPayload(array $overrides = []): array
{
    return [
        'visit_date' => '2026-10-25',
        'facility_name' => 'Klinik Pratama Sehat',
        'complaint' => 'Pusing dan sakit kepala berdenyut',
        'medications' => 'Paracetamol 500mg 3x1',
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// 1. Autentikasi
// ---------------------------------------------------------------------------

test('endpoints require authentication', function () {
    $this->getJson('/api/personal-medical-records')->assertUnauthorized();
    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload())->assertUnauthorized();
    $this->getJson('/api/personal-medical-records/1')->assertUnauthorized();
    $this->putJson('/api/personal-medical-records/1', validPersonalRecordPayload())->assertUnauthorized();
    $this->deleteJson('/api/personal-medical-records/1')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 2. Tambah Catatan Mandiri (POST)
// ---------------------------------------------------------------------------

test('user can create a personal medical record with status 201', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $payload = validPersonalRecordPayload();

    $response = $this->postJson('/api/personal-medical-records', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Catatan medis mandiri berhasil ditambahkan.')
        ->assertJsonPath('data.facility_name', 'Klinik Pratama Sehat')
        ->assertJsonPath('data.complaint', 'Pusing dan sakit kepala berdenyut')
        ->assertJsonPath('data.medications', 'Paracetamol 500mg 3x1')
        ->assertJsonPath('data.visit_date', '2026-10-25')
        ->assertJsonPath('data.source', 'USER_REPORTED')
        ->assertJsonPath('data.source_label', 'Catatan Mandiri');

    $this->assertDatabaseHas('personal_medical_records', [
        'personal_medical_record_id' => $response->json('data.personal_medical_record_id'),
        'user_id' => $user->user_id,
        'facility_name' => 'Klinik Pratama Sehat',
        'source' => 'USER_REPORTED',
    ]);
});

test('medications field is optional and can be null or empty', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $payload = validPersonalRecordPayload(['medications' => null]);

    $response = $this->postJson('/api/personal-medical-records', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.medications', null);
});

test('client cannot forge record ownership via user_id in payload', function () {
    $user = personalMedicalRecordUser();
    $otherUser = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $payload = validPersonalRecordPayload(['user_id' => $otherUser->user_id]);

    $response = $this->postJson('/api/personal-medical-records', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.user_id', $user->user_id);

    $this->assertDatabaseHas('personal_medical_records', [
        'personal_medical_record_id' => $response->json('data.personal_medical_record_id'),
        'user_id' => $user->user_id,
    ]);
    $this->assertDatabaseMissing('personal_medical_records', [
        'personal_medical_record_id' => $response->json('data.personal_medical_record_id'),
        'user_id' => $otherUser->user_id,
    ]);
});

// ---------------------------------------------------------------------------
// 3. Validasi Input
// ---------------------------------------------------------------------------

test('visit_date is required and must be a valid date', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload(['visit_date' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['visit_date']);

    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload(['visit_date' => 'invalid-date']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['visit_date']);
});

test('visit_date cannot be in the future', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $futureDate = Carbon::now('Asia/Jakarta')->addDays(2)->format('Y-m-d');

    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload(['visit_date' => $futureDate]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['visit_date']);
});

test('facility_name is required', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload(['facility_name' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['facility_name']);
});

test('complaint is required', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/personal-medical-records', validPersonalRecordPayload(['complaint' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['complaint']);
});

// ---------------------------------------------------------------------------
// 4. Daftar Catatan Mandiri (GET list) & Isolasi Pengguna
// ---------------------------------------------------------------------------

test('user only sees their own personal medical records', function () {
    $user = personalMedicalRecordUser();
    $otherUser = personalMedicalRecordUser();

    $userRecord = PersonalMedicalRecord::factory()->for($user, 'user')->create([
        'facility_name' => 'Klinik Milik User',
        'visit_date' => '2026-10-20',
    ]);
    $otherRecord = PersonalMedicalRecord::factory()->for($otherUser, 'user')->create([
        'facility_name' => 'Klinik Milik Orang Lain',
        'visit_date' => '2026-10-21',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/personal-medical-records');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.personal_medical_record_id', $userRecord->personal_medical_record_id)
        ->assertJsonPath('data.data.0.facility_name', 'Klinik Milik User');
});

test('records list is ordered by visit_date descending', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $older = PersonalMedicalRecord::factory()->for($user, 'user')->create(['visit_date' => '2026-10-10']);
    $newer = PersonalMedicalRecord::factory()->for($user, 'user')->create(['visit_date' => '2026-10-25']);

    $response = $this->getJson('/api/personal-medical-records');

    $response->assertOk();
    $data = $response->json('data.data');

    expect($data[0]['personal_medical_record_id'])->toBe($newer->personal_medical_record_id);
    expect($data[1]['personal_medical_record_id'])->toBe($older->personal_medical_record_id);
});

test('records list supports pagination', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    PersonalMedicalRecord::factory()->for($user, 'user')->count(3)->create();

    $response = $this->getJson('/api/personal-medical-records?per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonPath('data.total', 3);
});

test('records list can be filtered by visit_date_from and visit_date_to inclusively', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $rec1 = PersonalMedicalRecord::factory()->for($user, 'user')->create(['visit_date' => '2026-10-15']);
    $rec2 = PersonalMedicalRecord::factory()->for($user, 'user')->create(['visit_date' => '2026-10-20']);
    $rec3 = PersonalMedicalRecord::factory()->for($user, 'user')->create(['visit_date' => '2026-10-25']);

    $response = $this->getJson('/api/personal-medical-records?visit_date_from=2026-10-18&visit_date_to=2026-10-22');

    $response->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.personal_medical_record_id', $rec2->personal_medical_record_id);
});

test('records list returns empty array when user has no records', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/personal-medical-records')
        ->assertOk()
        ->assertJsonPath('data.data', []);
});

// ---------------------------------------------------------------------------
// 5. Detail Catatan (GET show)
// ---------------------------------------------------------------------------

test('user can view detail of their own record', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $record = PersonalMedicalRecord::factory()->for($user, 'user')->create([
        'facility_name' => 'RSUD Kota',
        'complaint' => 'Demam tinggi 3 hari',
        'medications' => 'Antibiotik dan Paracetamol',
        'visit_date' => '2026-10-22',
    ]);

    $this->getJson("/api/personal-medical-records/{$record->personal_medical_record_id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.personal_medical_record_id', $record->personal_medical_record_id)
        ->assertJsonPath('data.facility_name', 'RSUD Kota')
        ->assertJsonPath('data.source_label', 'Catatan Mandiri');
});

test('user gets 404 when viewing record belonging to another user', function () {
    $user = personalMedicalRecordUser();
    $otherUser = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $otherRecord = PersonalMedicalRecord::factory()->for($otherUser, 'user')->create();

    $this->getJson("/api/personal-medical-records/{$otherRecord->personal_medical_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

// ---------------------------------------------------------------------------
// 6. Update Catatan (PUT / PATCH)
// ---------------------------------------------------------------------------

test('user can update their own record', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $record = PersonalMedicalRecord::factory()->for($user, 'user')->create([
        'facility_name' => 'Klinik Lama',
        'complaint' => 'Keluhan Lama',
    ]);

    $this->putJson("/api/personal-medical-records/{$record->personal_medical_record_id}", [
        'visit_date' => '2026-10-26',
        'facility_name' => 'Klinik Baru',
        'complaint' => 'Keluhan Diperbarui',
        'medications' => 'Vitamin C 500mg',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.facility_name', 'Klinik Baru')
        ->assertJsonPath('data.complaint', 'Keluhan Diperbarui')
        ->assertJsonPath('data.visit_date', '2026-10-26');

    $this->assertDatabaseHas('personal_medical_records', [
        'personal_medical_record_id' => $record->personal_medical_record_id,
        'facility_name' => 'Klinik Baru',
    ]);
});

test('user gets 404 when updating record of another user', function () {
    $user = personalMedicalRecordUser();
    $otherUser = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $otherRecord = PersonalMedicalRecord::factory()->for($otherUser, 'user')->create([
        'facility_name' => 'Klinik Asli',
    ]);

    $this->putJson("/api/personal-medical-records/{$otherRecord->personal_medical_record_id}", [
        'visit_date' => '2026-10-25',
        'facility_name' => 'Upaya Pembajakan',
        'complaint' => 'Keluhan Pembajakan',
    ])
        ->assertNotFound();

    $this->assertDatabaseHas('personal_medical_records', [
        'personal_medical_record_id' => $otherRecord->personal_medical_record_id,
        'facility_name' => 'Klinik Asli',
    ]);
});

// ---------------------------------------------------------------------------
// 7. Delete Catatan (DELETE)
// ---------------------------------------------------------------------------

test('user can delete their own record', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $record = PersonalMedicalRecord::factory()->for($user, 'user')->create();

    $this->deleteJson("/api/personal-medical-records/{$record->personal_medical_record_id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('personal_medical_records', [
        'personal_medical_record_id' => $record->personal_medical_record_id,
    ]);
});

test('user gets 404 when deleting record of another user', function () {
    $user = personalMedicalRecordUser();
    $otherUser = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    $otherRecord = PersonalMedicalRecord::factory()->for($otherUser, 'user')->create();

    $this->deleteJson("/api/personal-medical-records/{$otherRecord->personal_medical_record_id}")
        ->assertNotFound();

    $this->assertDatabaseHas('personal_medical_records', [
        'personal_medical_record_id' => $otherRecord->personal_medical_record_id,
    ]);
});

// ---------------------------------------------------------------------------
// 8. Isolasi Terhadap OpenMRS
// ---------------------------------------------------------------------------

test('personal medical records are isolated and never recorded as OpenMRS integration logs', function () {
    $user = personalMedicalRecordUser();
    Sanctum::actingAs($user);

    Http::fake();

    $initialLogCount = IntegrationLog::query()->count();

    $response = $this->postJson('/api/personal-medical-records', validPersonalRecordPayload());
    $response->assertCreated();

    // Pastikan tidak ada HTTP request ke luar (OpenMRS)
    Http::assertNothingSent();

    // Pastikan integration_logs tidak bertambah
    expect(IntegrationLog::query()->count())->toBe($initialLogCount);
});

