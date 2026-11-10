<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

// ============================================================
// Avatar endpoint tests
// ============================================================

test('avatar default endpoint returns SVG with correct content type', function () {
    $response = $this->get('/api/avatar/default?name=Budi+Santoso');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');

    expect($response->getContent())->toContain('<svg');
    expect($response->getContent())->toContain('BS');
});

test('avatar extracts initials from multi-word name', function () {
    $response = $this->get('/api/avatar/default?name=Dewi+Lestari');

    $response->assertOk();
    expect($response->getContent())->toContain('DL');
});

test('avatar extracts first two characters from single-word name', function () {
    $response = $this->get('/api/avatar/default?name=Ahmad');

    $response->assertOk();
    expect($response->getContent())->toContain('AH');
});

test('avatar uses US as fallback for empty name or default User', function () {
    $response = $this->get('/api/avatar/default?name=');

    $response->assertOk();
    // Default fallback 'User' menghasilkan inisial 'US'
    expect($response->getContent())->toContain('>US<');
});

test('avatar uses US as fallback when name param is omitted', function () {
    $response = $this->get('/api/avatar/default');

    $response->assertOk();
    expect($response->getContent())->toContain('>US<');
});

test('avatar sanitizes XSS attempt in name parameter', function () {
    $response = $this->get('/api/avatar/default?name=<script>alert(1)</script>');

    $response->assertOk();
    // Konten tidak boleh mengandung tag script mentah
    expect($response->getContent())->not->toContain('<script>');
    // Karakter dikodekan sebagai HTML entity
    expect($response->getContent())->toContain('&lt;');
});

test('avatar uses last word initial for names with more than two words', function () {
    // "John Michael Doe" → first initial J + last initial D = "JD"
    $response = $this->get('/api/avatar/default?name=John+Michael+Doe');

    $response->assertOk();
    expect($response->getContent())->toContain('JD');
});

// ============================================================
// /api/me response structure
// ============================================================

test('GET /api/me returns expected fields and excludes sensitive data', function () {
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($role, 'role')->create([
        'name' => 'Siti Rahayu',
        'gender' => 'FEMALE',
        'date_of_birth' => '1990-05-15',
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $user->user_id)
        ->assertJsonPath('data.name', 'Siti Rahayu')
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.gender', 'FEMALE')
        ->assertJsonPath('data.date_of_birth', '1990-05-15');

    $data = $response->json('data');
    expect($data)->not->toHaveKey('password');
    expect($data)->not->toHaveKey('nik');
    expect($data)->not->toHaveKey('nik_hash');
    expect($data)->not->toHaveKey('phone');
    expect($data)->not->toHaveKey('google_sub');
    expect($data)->not->toHaveKey('avatar_path');
    expect($data)->toHaveKey('avatar_url');
});

test('GET /api/me includes avatar_url pointing to local SVG when no photo uploaded', function () {
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($role, 'role')->create(['name' => 'Budi Santoso']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/me');

    $avatarUrl = $response->json('data.avatar_url');
    expect($avatarUrl)->toContain('/api/avatar/default');
    expect($avatarUrl)->toContain('Budi');
});

test('GET /api/me requires authentication', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonPath('success', false);
});
