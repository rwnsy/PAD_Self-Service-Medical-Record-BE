<?php

use App\Models\Role;
use App\Models\User;
use App\Services\CalorieEstimationService;
use App\Services\Contracts\CalorieEstimationServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function calorieTestUser(array $attributes = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attributes);
}

test('service container resolves CalorieEstimationServiceInterface to CalorieEstimationService', function () {
    $service = app(CalorieEstimationServiceInterface::class);

    expect($service)->toBeInstanceOf(CalorieEstimationService::class);
});

test('calorie estimation requires authentication', function () {
    $this->postJson('/api/calorie-estimation', [])
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('an authenticated user can estimate food calories with private storage and signed URL', function () {
    Storage::fake('local');

    $user = calorieTestUser();
    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->image('healthy-meal.jpg', 800, 600)->size(1500);

    $response = $this->postJson('/api/calorie-estimation', [
        'image' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_mock', true)
        ->assertJsonPath('data.total_calories_min', 470)
        ->assertJsonPath('data.total_calories_max', 595)
        ->assertJsonPath('data.total_calories_formatted', '~470–595 kkal');

    $imageUrl = $response->json('data.image_url');
    expect($imageUrl)->not->toBeNull();
    expect($imageUrl)->toContain('/api/calorie-estimation/image/');
    expect($imageUrl)->toContain('signature=');

    // Ekstrak nama file dari URL
    $parsedUrl = parse_url($imageUrl, PHP_URL_PATH);
    $filename = basename($parsedUrl);

    // Verifikasi bahwa file tersimpan di storage privat (local disk)
    Storage::disk('local')->assertExists('calorie-estimates/'.$filename);

    // Akses gambar menggunakan signed URL yang valid
    $imageResponse = $this->get($imageUrl);
    $imageResponse->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    // Akses gambar tanpa signature harus ditolak (403 Forbidden)
    $unsignedUrl = url('/api/calorie-estimation/image/'.$filename);
    $this->get($unsignedUrl)->assertForbidden();

    // Akses gambar dengan signature yang diubah / dipalsukan harus ditolak (403)
    $tamperedUrl = $imageUrl.'bad';
    $this->get($tamperedUrl)->assertForbidden();

    // Periksa rincian makanan dummy (6 komponen)
    $foods = $response->json('data.foods');
    expect($foods)->toBeArray();
    expect(count($foods))->toBe(6);

    $foodNames = array_column($foods, 'name');
    expect($foodNames)->toContain(
        'Ikan Salmon Panggang',
        'Nasi',
        'Alpukat',
        'Jagung Manis',
        'Saus/Dressing',
        'Tomat Ceri'
    );

    // Validasi integritas perhitungan rentang kalori total
    $calculatedMin = array_sum(array_column($foods, 'calories_min'));
    $calculatedMax = array_sum(array_column($foods, 'calories_max'));
    expect($response->json('data.total_calories_min'))->toBe($calculatedMin);
    expect($response->json('data.total_calories_max'))->toBe($calculatedMax);
});

test('calorie estimation validation rejects missing file, invalid types, or files > 5MB', function () {
    Storage::fake('local');

    $user = calorieTestUser();
    Sanctum::actingAs($user);

    // 1. Image field missing
    $this->postJson('/api/calorie-estimation', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    // 2. Non-image file (text file)
    $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
    $this->postJson('/api/calorie-estimation', ['image' => $txt])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    // 3. File size exceeds 5MB (5121 KB)
    $oversized = UploadedFile::fake()->image('giant-food.jpg')->size(5121);
    $this->postJson('/api/calorie-estimation', ['image' => $oversized])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);
});

test('prune command removes calorie estimate files older than threshold from private storage', function () {
    Storage::fake('local');

    // File baru (< 24 jam)
    Storage::disk('local')->put('calorie-estimates/recent.jpg', 'fake-image-content-recent');

    // File lama (> 24 jam)
    Storage::disk('local')->put('calorie-estimates/old.jpg', 'fake-image-content-old');
    $oldFilePath = Storage::disk('local')->path('calorie-estimates/old.jpg');
    touch($oldFilePath, now()->subHours(25)->timestamp);

    $this->artisan('calorie-estimates:prune', ['--hours' => 24])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('calorie-estimates/recent.jpg');
    Storage::disk('local')->assertMissing('calorie-estimates/old.jpg');
});

test('web UI routes for test pages are accessible in local/testing environment', function () {
    $this->get('/calorie-estimation')
        ->assertOk()
        ->assertSee('Estimasi Kalori Makanan')
        ->assertSee('Data Simulasi')
        ->assertSee('Kamera')
        ->assertSee('Galeri');

    $this->get('/profile-photo-test')
        ->assertOk()
        ->assertSee('Kelola Foto Profil')
        ->assertSee('Foto Profil Pengguna')
        ->assertSee('Hapus Foto Profil');
});

test('web UI test pages are not exposed in production environment', function () {
    config(['app.env' => 'production']);

    $this->get('/calorie-estimation')->assertNotFound();
    $this->get('/profile-photo-test')->assertNotFound();
    $this->get('/google-login-test')->assertNotFound();
});
