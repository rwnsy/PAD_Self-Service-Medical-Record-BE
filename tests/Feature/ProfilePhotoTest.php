<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

function profilePhotoUser(array $attributes = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attributes);
}

test('avatar_path is not fillable to prevent mass assignment vulnerabilities', function () {
    $user = new User;
    expect($user->isFillable('avatar_path'))->toBeFalse();

    $user->fill(['avatar_path' => 'malicious/path.jpg']);
    expect($user->avatar_path)->toBeNull();

    // Pastikan internal forceFill tetap berfungsi untuk kontroler internal
    $user->forceFill(['avatar_path' => 'avatars/valid.jpg']);
    expect($user->avatar_path)->toBe('avatars/valid.jpg');

    // Pastikan avatar_path tetap hidden dari array / JSON
    expect($user->toArray())->not->toHaveKey('avatar_path');
});

test('uploading a profile photo requires authentication', function () {
    $this->postJson('/api/profile/photo', [])
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('deleting a profile photo requires authentication', function () {
    $this->deleteJson('/api/profile/photo')
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('an authenticated user can upload a valid profile photo', function () {
    Storage::fake('public');

    $user = profilePhotoUser(['name' => 'Budi Santoso']);
    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->image('avatar.jpg', 400, 400)->size(1024);

    $response = $this->postJson('/api/profile/photo', [
        'photo' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Foto profil berhasil diperbarui.');

    $avatarUrl = $response->json('data.avatar_url');
    expect($avatarUrl)->not->toBeNull();
    expect($avatarUrl)->toContain('/storage/avatars/');

    // Path internal avatar_path harus hidden dan tidak bocor ke respons
    $response->assertJsonMissingPath('data.user.avatar_path');
    $response->assertJsonMissingPath('data.avatar_path');

    $user->refresh();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('uploading a new photo deletes the previous photo from public storage', function () {
    Storage::fake('public');

    $user = profilePhotoUser(['name' => 'Siti Aminah']);
    Sanctum::actingAs($user);

    // Upload foto pertama
    $firstFile = UploadedFile::fake()->image('first.jpg', 300, 300);
    $this->postJson('/api/profile/photo', ['photo' => $firstFile])->assertOk();
    $firstPath = $user->refresh()->avatar_path;
    Storage::disk('public')->assertExists($firstPath);

    // Upload foto kedua
    $secondFile = UploadedFile::fake()->image('second.png', 400, 400);
    $this->postJson('/api/profile/photo', ['photo' => $secondFile])->assertOk();
    $secondPath = $user->refresh()->avatar_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($secondPath);
    Storage::disk('public')->assertMissing($firstPath);
});

test('validation fails when photo is missing, not an image, or exceeds 5MB', function () {
    Storage::fake('public');

    $user = profilePhotoUser();
    Sanctum::actingAs($user);

    // 1. Kosong
    $this->postJson('/api/profile/photo', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);

    // 2. Format bukan gambar (PDF)
    $pdf = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');
    $this->postJson('/api/profile/photo', ['photo' => $pdf])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);

    // 3. Melebihi 5MB (5121 KB)
    $largeImage = UploadedFile::fake()->image('large.jpg')->size(5121);
    $this->postJson('/api/profile/photo', ['photo' => $largeImage])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);
});

test('an authenticated user can delete their profile photo', function () {
    Storage::fake('public');

    $user = profilePhotoUser(['name' => 'Ahmad Fauzi']);
    Sanctum::actingAs($user);

    // Upload foto terlebih dahulu
    $file = UploadedFile::fake()->image('avatar.webp', 200, 200);
    $this->postJson('/api/profile/photo', ['photo' => $file])->assertOk();
    $path = $user->refresh()->avatar_path;
    Storage::disk('public')->assertExists($path);

    // Hapus foto profil
    $response = $this->deleteJson('/api/profile/photo');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Foto profil berhasil dihapus.');

    $user->refresh();
    expect($user->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);

    // avatar_url kembali ke fallback lokal tanpa pihak ketiga
    $avatarUrl = $response->json('data.avatar_url');
    expect($avatarUrl)->toContain('/api/avatar/default');
    expect($avatarUrl)->toContain('Ahmad');
    expect($avatarUrl)->not->toContain('ui-avatars.com');
});

test('avatar_url falls back to local SVG avatar without leaking name to external service', function () {
    $user = profilePhotoUser(['name' => 'Dewi Lestari', 'avatar_path' => null]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Dewi Lestari');

    $avatarUrl = $response->json('data.avatar_url');
    expect($avatarUrl)->toContain('/api/avatar/default');
    expect($avatarUrl)->toContain('Dewi');
    expect($avatarUrl)->not->toContain('ui-avatars.com');
});

test('dashboard response includes user local avatar_url', function () {
    $user = profilePhotoUser(['name' => 'Rian Hidayat']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.profile.name', 'Rian Hidayat');

    $avatarUrl = $response->json('data.profile.avatar_url');
    expect($avatarUrl)->toContain('/api/avatar/default');
    expect($avatarUrl)->toContain('Rian');
    expect($avatarUrl)->not->toContain('ui-avatars.com');
});

test('local avatar endpoint returns SVG with user initials and proper headers', function () {
    $response = $this->get('/api/avatar/default?name=Dewi+Lestari');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');

    $content = $response->getContent();
    expect($content)->toContain('<svg');
    expect($content)->toContain('#0D8ABC');
    expect($content)->toContain('DL');
});

test('local avatar endpoint uses default initial when name is omitted', function () {
    $response = $this->get('/api/avatar/default');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');

    $content = $response->getContent();
    expect($content)->toContain('U');
});
