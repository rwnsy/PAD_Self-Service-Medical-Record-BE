<?php

use App\Models\HealthRecord;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function healthRecordUser(): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create();
}

function validHealthRecordPayload(array $overrides = []): array
{
    return [
        'height' => 170,
        'weight' => 65,
        'systolic' => 120,
        'diastolic' => 75,
        'recorded_at' => '2026-10-20T08:00:00+07:00',
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// Record gabungan (BMI + tekanan darah)
// ---------------------------------------------------------------------------

test('an authenticated user can create a self-assessment record and cannot assign another owner', function () {
    $user = healthRecordUser();
    $otherUser = healthRecordUser();
    Sanctum::actingAs($user);

    // systolic=120, diastolic=75: keduanya di bawah ambang peringatan (130/80)
    // sehingga warning_status = NORMAL
    $response = $this->postJson('/api/health-records', validHealthRecordPayload([
        'user_id' => $otherUser->user_id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $user->user_id)
        ->assertJsonPath('data.bmi', '22.49')
        ->assertJsonPath('data.warning_status', 'NORMAL');

    $this->assertDatabaseHas('health_records', [
        'user_id' => $user->user_id,
        'bmi' => 22.49,
        'warning_status' => 'NORMAL',
    ]);
});

test('the API calculates BMI server-side and ignores a supplied BMI value', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // systolic=120, diastolic=75: keduanya di bawah ambang (130/80) → NORMAL
    // BMI=25.00 dari 180cm/81kg (full-precision=25.00 persis → BERAT_BERLEBIH)
    $response = $this->postJson('/api/health-records', validHealthRecordPayload([
        'height' => 180,
        'weight' => 81,
        'bmi' => 999,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', '25.00')
        ->assertJsonPath('data.bmi_category', 'BERAT_BERLEBIH')
        ->assertJsonPath('data.warning_status', 'NORMAL');
});

test('a combined record with elevated blood pressure receives an ATTENTION warning', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // systolic=130 tepat di ambang bawah peringatan
    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'systolic' => 130,
        'diastolic' => 75,
        'recorded_at' => '2026-10-20',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.warning_status', 'ATTENTION')
        ->assertJsonPath('data.bmi_category', 'IDEAL');
});

test('a combined record with urgent blood pressure receives an URGENT warning', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'systolic' => 185,
        'diastolic' => 75,
        'recorded_at' => '2026-10-20',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.warning_status', 'URGENT');
});

// ---------------------------------------------------------------------------
// Record BMI-only (tanpa tekanan darah)
// ---------------------------------------------------------------------------

test('a BMI-only assessment can be stored without blood-pressure values', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-20T08:00:00+07:00',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', '22.49')
        ->assertJsonPath('data.bmi_category', 'IDEAL')
        ->assertJsonPath('data.systolic', null)
        ->assertJsonPath('data.diastolic', null)
        // Tidak ada tekanan darah yang diukur → warning harus null, bukan 'NORMAL'
        ->assertJsonPath('data.warning_status', null)
        ->assertJsonPath('data.warning_message', null);
});

test('BMI-only record warning_status is null not NORMAL when no BP data is present', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 180,
        'weight' => 90,
        'recorded_at' => '2026-10-20',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.warning_status', null)
        ->assertJsonPath('data.warning_message', null);
});

// ---------------------------------------------------------------------------
// Record tekanan darah-only (tanpa BMI)
// ---------------------------------------------------------------------------

test('a blood-pressure-only assessment can be stored without BMI values', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'systolic' => 150,
        'diastolic' => 95,
        'recorded_at' => '2026-10-20T08:00:00+07:00',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.height', null)
        ->assertJsonPath('data.weight', null)
        ->assertJsonPath('data.bmi', null)
        ->assertJsonPath('data.bmi_category', null)
        ->assertJsonPath('data.bmi_message', null)
        ->assertJsonPath('data.warning_status', 'ATTENTION');
});

test('a blood-pressure-only record with normal BP receives NORMAL warning', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'systolic' => 115,
        'diastolic' => 75,
        'recorded_at' => '2026-10-20',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', null)
        ->assertJsonPath('data.bmi_category', null)
        ->assertJsonPath('data.warning_status', 'NORMAL');
});

// ---------------------------------------------------------------------------
// Validasi pengukuran
// ---------------------------------------------------------------------------

test('health record measurements are validated', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', validHealthRecordPayload([
        'height' => 0,
        'systolic' => 70,
        'diastolic' => 90,
        'recorded_at' => now()->addDay()->toIso8601String(),
    ]));

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['height', 'systolic', 'recorded_at']);
});

test('health record endpoints require authentication', function () {
    $this->postJson('/api/health-records', validHealthRecordPayload())
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('a user cannot view update or delete another users health record', function () {
    $owner = healthRecordUser();
    $otherUser = healthRecordUser();
    $healthRecord = HealthRecord::factory()->for($otherUser, 'user')->create();
    Sanctum::actingAs($owner);

    $this->getJson("/api/health-records/{$healthRecord->health_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);

    $this->putJson("/api/health-records/{$healthRecord->health_record_id}", validHealthRecordPayload())
        ->assertNotFound()
        ->assertJsonPath('success', false);

    $this->deleteJson("/api/health-records/{$healthRecord->health_record_id}")
        ->assertNotFound()
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('health_records', [
        'health_record_id' => $healthRecord->health_record_id,
    ]);
});

// ---------------------------------------------------------------------------
// Filter riwayat berdasarkan tanggal — timezone-aware
// ---------------------------------------------------------------------------

test('health record history is filtered by owner and inclusive recorded date range', function () {
    $user = healthRecordUser();
    $otherUser = healthRecordUser();

    // Timestamp lama yang disimpan dalam UTC sudah ada — 2026-10-10 WIB = 2026-10-09T17:00:00Z
    // Filter menggunakan batas WIB sehingga record ini tetap muncul untuk tanggal 2026-10-10
    $earlierRecord = HealthRecord::factory()->for($user, 'user')->create([
        'recorded_at' => Carbon::parse('2026-10-10 08:00:00', 'Asia/Jakarta')->utc(),
    ]);
    $latestRecord = HealthRecord::factory()->for($user, 'user')->create([
        'recorded_at' => Carbon::parse('2026-10-10 10:00:00', 'Asia/Jakarta')->utc(),
    ]);
    HealthRecord::factory()->for($otherUser, 'user')->create([
        'recorded_at' => Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta')->utc(),
    ]);
    HealthRecord::factory()->for($user, 'user')->create([
        'recorded_at' => Carbon::parse('2026-10-11 10:00:00', 'Asia/Jakarta')->utc(),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/health-records?recorded_from=2026-10-10&recorded_to=2026-10-10')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('data.data.0.health_record_id', $latestRecord->health_record_id)
        ->assertJsonPath('data.data.1.health_record_id', $earlierRecord->health_record_id);
});

test('health record history supports pagination', function () {
    $user = healthRecordUser();
    HealthRecord::factory()->for($user, 'user')->count(2)->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/health-records?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.total', 2);
});

test('a user can update and delete an owned health record', function () {
    $user = healthRecordUser();
    $healthRecord = HealthRecord::factory()->for($user, 'user')->create();
    Sanctum::actingAs($user);

    // systolic=120, diastolic=75 → di bawah ambang → NORMAL
    // BMI 180/81 → full-precision = 25.00 persis → BERAT_BERLEBIH
    $this->putJson("/api/health-records/{$healthRecord->health_record_id}", validHealthRecordPayload([
        'height' => 180,
        'weight' => 81,
    ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bmi', '25.00')
        ->assertJsonPath('data.bmi_category', 'BERAT_BERLEBIH')
        ->assertJsonPath('data.warning_status', 'NORMAL');

    $this->deleteJson("/api/health-records/{$healthRecord->health_record_id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('health_records', [
        'health_record_id' => $healthRecord->health_record_id,
    ]);
});

test('a partial update preserves health-record values that were not submitted', function () {
    $user = healthRecordUser();
    $healthRecord = HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170,
        'weight' => 65,
        'systolic' => null,
        'diastolic' => null,
        'bmi' => 22.49,
    ]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/health-records/{$healthRecord->health_record_id}", [
        'weight' => 70,
    ])
        ->assertOk()
        ->assertJsonPath('data.height', '170.00')
        ->assertJsonPath('data.weight', '70.00')
        ->assertJsonPath('data.bmi', '24.22')
        ->assertJsonPath('data.systolic', null)
        // BMI-only → warning_status harus null
        ->assertJsonPath('data.warning_status', null);
});

// ---------------------------------------------------------------------------
// Masalah 3: Ketepatan kategori BMI di batas ambang (full-precision)
// ---------------------------------------------------------------------------

test('BMI category is RENDAH when BMI is below 18.5', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // 170cm, 53 kg → BMI full = 18.34... → RENDAH
    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 53,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi_category', 'RENDAH')
        ->assertJsonPath('data.bmi_message', 'Berat badan berada di bawah rentang ideal. Perhatikan kecukupan gizi dan pola makan yang seimbang.');
});

test('BMI category is IDEAL just above boundary 18.5', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // 170cm, 53.47 kg → BMI full = 18.5017... > 18.5 → IDEAL
    // (catatan: 47.36/160cm menghasilkan 18.4999... dalam floating point,
    //  sehingga sebenarnya RENDAH; 53.47/170cm dipilih karena hasilnya > 18.5)
    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 53.47,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi_category', 'IDEAL')
        ->assertJsonPath('data.bmi_message', 'BMI berada dalam rentang berat badan ideal. Pertahankan pola hidup sehat.');
});

test('BMI category is IDEAL for a typical healthy weight', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 163,
        'weight' => 62.2,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', '23.41')
        ->assertJsonPath('data.bmi_category', 'IDEAL')
        ->assertJsonPath('data.bmi_message', 'BMI berada dalam rentang berat badan ideal. Pertahankan pola hidup sehat.');
});

test('BMI category is IDEAL when full-precision BMI is just below 25 but rounds to 25.00', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // 170cm, 72.24 kg → BMI full = 24.9965... < 25 → IDEAL
    // Namun round(24.9965, 2) = 25.00 — tanpa presisi penuh akan salah menjadi BERAT_BERLEBIH
    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 72.24,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', '25.00')       // tampilan dibulatkan ke 25.00
        ->assertJsonPath('data.bmi_category', 'IDEAL'); // tapi full-precision < 25 → IDEAL
});

test('BMI category is BERAT_BERLEBIH at boundary 25 full-precision', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // 180cm, 81 kg → BMI full = 25.00 persis (IEEE 754) → BERAT_BERLEBIH
    $response = $this->postJson('/api/health-records', [
        'height' => 180,
        'weight' => 81,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', '25.00')
        ->assertJsonPath('data.bmi_category', 'BERAT_BERLEBIH')
        ->assertJsonPath('data.bmi_message', 'BMI berada di atas rentang ideal. Perhatikan pola makan dan aktivitas fisik.');
});

test('BMI category is BERAT_BERLEBIH above 25', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 85,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi_category', 'BERAT_BERLEBIH');
});

test('bmi_category and bmi_message are null when no BMI measurement is present', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'systolic' => 120,
        'diastolic' => 80,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bmi', null)
        ->assertJsonPath('data.bmi_category', null)
        ->assertJsonPath('data.bmi_message', null);
});

// ---------------------------------------------------------------------------
// Masalah 2: Konsistensi tanggal kalender dan filter riwayat
// ---------------------------------------------------------------------------

test('recorded_at accepts a date-only string YYYY-MM-DD', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true);
});

test('recorded_date field preserves the calendar date sent by the client', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-25',
    ]);

    $response->assertCreated();

    // recorded_date dikembalikan dalam YYYY-MM-DD menggunakan zona waktu Asia/Jakarta
    expect($response->json('data.recorded_date'))->toBe('2026-10-25');
});

test('a date-only record is found when filtered by the same calendar date', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    // Buat record via API dengan tanggal-only
    $createResponse = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-25',
    ]);
    $createResponse->assertCreated();
    $recordId = $createResponse->json('data.health_record_id');

    // Filter tanggal yang sama harus menemukan record
    $indexResponse = $this->getJson('/api/health-records?recorded_from=2026-10-25&recorded_to=2026-10-25');
    $indexResponse
        ->assertOk()
        ->assertJsonPath('success', true);

    $ids = collect($indexResponse->json('data.data'))->pluck('health_record_id');
    expect($ids)->toContain($recordId);
});

test('a date-only record is not found when filtered by the previous calendar date', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $createResponse = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-25',
    ]);
    $createResponse->assertCreated();
    $recordId = $createResponse->json('data.health_record_id');

    // Filter hari sebelumnya tidak boleh mengambil record tanggal 25
    $indexResponse = $this->getJson('/api/health-records?recorded_from=2026-10-24&recorded_to=2026-10-24');
    $indexResponse->assertOk();

    $ids = collect($indexResponse->json('data.data'))->pluck('health_record_id');
    expect($ids)->not->toContain($recordId);
});

test('a legacy full-timestamp record remains readable after filter changes', function () {
    $user = healthRecordUser();

    // Record lama tersimpan dengan timestamp UTC penuh (seperti data historis)
    // 2026-10-10 08:00 WIB = 2026-10-10 01:00 UTC
    $legacyRecord = HealthRecord::factory()->for($user, 'user')->create([
        'recorded_at' => Carbon::parse('2026-10-10 08:00:00', 'Asia/Jakarta')->utc(),
    ]);

    Sanctum::actingAs($user);

    // Filter per tanggal kalender WIB harus menemukan record tersebut
    $indexResponse = $this->getJson('/api/health-records?recorded_from=2026-10-10&recorded_to=2026-10-10');
    $indexResponse->assertOk();

    $ids = collect($indexResponse->json('data.data'))->pluck('health_record_id');
    expect($ids)->toContain($legacyRecord->health_record_id);

    // Tidak muncul di hari sebelumnya
    $prevResponse = $this->getJson('/api/health-records?recorded_from=2026-10-09&recorded_to=2026-10-09');
    $prevResponse->assertOk();

    $prevIds = collect($prevResponse->json('data.data'))->pluck('health_record_id');
    expect($prevIds)->not->toContain($legacyRecord->health_record_id);
});

// ---------------------------------------------------------------------------
// Validasi tanggal
// ---------------------------------------------------------------------------

test('a future recorded_at date is rejected', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => now()->addDay()->format('Y-m-d'),
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recorded_at']);
});

test('an invalid recorded_at format is rejected', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => 'bukan-tanggal',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recorded_at']);
});

// ---------------------------------------------------------------------------
// Validasi: tubuh pengukuran tidak boleh kosong
// ---------------------------------------------------------------------------

test('a request with no measurement fields is rejected', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['measurements']);
});

test('providing only height without weight is rejected', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'height' => 170,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['height']);
});

test('providing only systolic without diastolic is rejected', function () {
    $user = healthRecordUser();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/health-records', [
        'systolic' => 120,
        'recorded_at' => '2026-10-25',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['systolic']);
});
