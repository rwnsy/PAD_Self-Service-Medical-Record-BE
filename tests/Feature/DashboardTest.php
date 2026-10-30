<?php

use App\Models\HealthRecord;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function dashboardUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attrs);
}

test('dashboard requires authentication', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized()->assertJsonPath('success', false);
});

test('dashboard returns null summary when user has no records', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.latest_bmi', null)
        ->assertJsonPath('data.summary.latest_blood_pressure', null)
        ->assertJsonPath('data.summary.total_assessments', 0)
        ->assertJsonPath('data.charts.bmi_trend', [])
        ->assertJsonPath('data.charts.blood_pressure_trend', []);
});

test('dashboard BMI summary uses the most recent BMI record', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    // Record lama
    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => Carbon::parse('2026-10-20', 'Asia/Jakarta')->utc(),
    ]);
    // Record terbaru
    $latest = HealthRecord::factory()->for($user, 'user')->create([
        'height' => 175, 'weight' => 70, 'bmi' => 22.86, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => Carbon::parse('2026-10-25', 'Asia/Jakarta')->utc(),
    ]);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk()
        ->assertJsonPath('data.summary.latest_bmi.health_record_id', $latest->health_record_id)
        ->assertJsonPath('data.summary.latest_bmi.bmi', '22.86');
});

test('dashboard BP summary uses the most recent BP record separately from BMI', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    $bmiRecord = HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => Carbon::parse('2026-10-25', 'Asia/Jakarta')->utc(),
    ]);
    $bpRecord = HealthRecord::factory()->for($user, 'user')->create([
        'height' => null, 'weight' => null, 'bmi' => null, 'bmi_category' => null,
        'systolic' => 125, 'diastolic' => 82,
        'warning_status' => 'ATTENTION',
        'recorded_at' => Carbon::parse('2026-10-24', 'Asia/Jakarta')->utc(),
    ]);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk()
        ->assertJsonPath('data.summary.latest_bmi.health_record_id', $bmiRecord->health_record_id)
        ->assertJsonPath('data.summary.latest_blood_pressure.health_record_id', $bpRecord->health_record_id)
        ->assertJsonPath('data.summary.latest_blood_pressure.systolic', 125);
});

test('dashboard only shows data belonging to the authenticated user', function () {
    $user = dashboardUser();
    $otherUser = dashboardUser();
    Sanctum::actingAs($user);

    HealthRecord::factory()->for($otherUser, 'user')->create([
        'height' => 180, 'weight' => 90, 'bmi' => 27.78, 'bmi_category' => 'BERAT_BERLEBIH',
        'recorded_at' => now(),
    ]);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk()
        ->assertJsonPath('data.summary.latest_bmi', null)
        ->assertJsonPath('data.summary.total_assessments', 0);
});

test('all dashboard periods are accepted', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    foreach (['week', 'month', '3months', '6months'] as $period) {
        $this->getJson("/api/dashboard?period={$period}")
            ->assertOk()
            ->assertJsonPath('data.period', $period);
    }
});

test('dashboard defaults to month period when period is omitted', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.period', 'month');
});

test('invalid period returns 422', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/dashboard?period=year')->assertUnprocessable()->assertJsonValidationErrors(['period']);
});

test('dashboard chart data is ordered chronologically', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 70, 'bmi' => 24.22, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => Carbon::parse('2026-10-23', 'Asia/Jakarta')->utc(),
    ]);
    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => Carbon::parse('2026-10-25', 'Asia/Jakarta')->utc(),
    ]);

    $response = $this->getJson('/api/dashboard?period=month');
    $trend = $response->json('data.charts.bmi_trend');

    expect(count($trend))->toBe(2);
    // Urutan ascending: yang lebih lama dulu
    expect($trend[0]['recorded_date'] <= $trend[1]['recorded_date'])->toBeTrue();
});

test('dashboard recorded_date uses Asia/Jakarta timezone boundary', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    // Record dengan tanggal kalender '2026-10-25'
    $record = HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'systolic' => null, 'diastolic' => null,
        'recorded_at' => '2026-10-25',
    ]);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk();
    $bmi = $response->json('data.summary.latest_bmi');
    expect($bmi['recorded_date'])->toBe('2026-10-25');
});

test('dashboard profile does not expose sensitive fields', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk();
    $profile = $response->json('data.profile');

    expect($profile)->toHaveKeys(['user_id', 'name', 'email', 'gender', 'date_of_birth']);
    expect($profile)->not->toHaveKey('nik');
    expect($profile)->not->toHaveKey('password');
    expect($profile)->not->toHaveKey('nik_hash');
});

test('dashboard latest_warning returns latest warning even when outside selected period', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    // Record dibuat 20 hari lalu (di luar period week)
    HealthRecord::factory()->for($user, 'user')->create([
        'systolic' => 140,
        'diastolic' => 90,
        'warning_status' => 'ATTENTION',
        'warning_message' => 'Tekanan darah di atas normal.',
        'recorded_at' => Carbon::now('Asia/Jakarta')->subDays(20),
    ]);

    $response = $this->getJson('/api/dashboard?period=week');

    $response->assertOk()
        ->assertJsonPath('data.period', 'week')
        ->assertJsonPath('data.summary.latest_warning.warning_status', 'ATTENTION')
        ->assertJsonPath('data.summary.total_assessments', 0)
        ->assertJsonPath('data.charts.blood_pressure_trend', []);
});

test('dashboard period accurately counts early morning (00:00-06:59 WIB) and late night (23:59 WIB) records', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    // Record dini hari hari ini (02:00 WIB)
    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'recorded_at' => Carbon::now('Asia/Jakarta')->startOfDay()->addHours(2),
    ]);

    // Record kemarin malam (23:30 WIB) — masih dalam periode week
    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 66, 'bmi' => 22.84, 'bmi_category' => 'IDEAL',
        'recorded_at' => Carbon::now('Asia/Jakarta')->subDay()->startOfDay()->addHours(23)->addMinutes(30),
    ]);

    $response = $this->getJson('/api/dashboard?period=week');

    $response->assertOk()
        ->assertJsonPath('data.summary.total_assessments', 2);

    $trend = $response->json('data.charts.bmi_trend');
    expect(count($trend))->toBe(2);
    // Urutan kronologis: yang lebih lampau dulu
    expect($trend[0]['bmi'])->toBe('22.84');
    expect($trend[1]['bmi'])->toBe('22.49');
});

test('dashboard handles UTC Carbon instance for midnight boundary without date shifting', function () {
    $user = dashboardUser();
    Sanctum::actingAs($user);

    // 2026-10-25 00:00:00 WIB dikonversi ke UTC adalah 2026-10-24 17:00:00 UTC
    // Mutator HealthRecord harus menormalkan kembali ke Asia/Jakarta sehingga tanggal tetap 2026-10-25
    HealthRecord::factory()->for($user, 'user')->create([
        'height' => 170, 'weight' => 65, 'bmi' => 22.49, 'bmi_category' => 'IDEAL',
        'recorded_at' => Carbon::parse('2026-10-25 00:00:00', 'Asia/Jakarta')->utc(),
    ]);

    $response = $this->getJson('/api/dashboard?period=month');

    $response->assertOk();
    $bmi = $response->json('data.summary.latest_bmi');
    expect($bmi['recorded_date'])->toBe('2026-10-25');
});
