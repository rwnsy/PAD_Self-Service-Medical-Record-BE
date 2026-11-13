<?php

use App\Models\ActivityLog;
use App\Models\HealthRecord;
use App\Models\OpenmrsConfig;
use App\Models\PersonalMedicalRecord;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function adminUser(): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'ADMIN']);

    return User::factory()->for($role, 'role')->create();
}

function regularUser(): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create();
}

// ---------------------------------------------------------------------------
// Auth guard — guest cannot access any admin endpoint
// ---------------------------------------------------------------------------

test('guest cannot access admin dashboard', function () {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();
});

test('guest cannot access admin user list', function () {
    $this->getJson('/api/admin/users')->assertUnauthorized();
});

test('guest cannot access admin activity logs', function () {
    $this->getJson('/api/admin/activity-logs')->assertUnauthorized();
});

test('guest cannot access admin integration logs', function () {
    $this->getJson('/api/admin/integration-logs')->assertUnauthorized();
});

test('guest cannot access admin openmrs config', function () {
    $this->getJson('/api/admin/openmrs/config')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Role guard — USER role is forbidden from admin endpoints
// ---------------------------------------------------------------------------

test('a user with USER role is forbidden from admin dashboard', function () {
    Sanctum::actingAs(regularUser());

    $this->getJson('/api/admin/dashboard')->assertForbidden();
});

test('a user with USER role is forbidden from admin user list', function () {
    Sanctum::actingAs(regularUser());

    $this->getJson('/api/admin/users')->assertForbidden();
});

test('a user with USER role is forbidden from admin activity logs', function () {
    Sanctum::actingAs(regularUser());

    $this->getJson('/api/admin/activity-logs')->assertForbidden();
});

test('a user with USER role is forbidden from admin openmrs config', function () {
    Sanctum::actingAs(regularUser());

    $this->getJson('/api/admin/openmrs/config')->assertForbidden();
});

test('a user with USER role cannot update openmrs config', function () {
    Sanctum::actingAs(regularUser());

    $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://example.com',
        'credential' => ['username' => 'u', 'password' => 'p'],
    ])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Admin dashboard
// ---------------------------------------------------------------------------

test('admin can access admin dashboard and receives expected structure', function () {
    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'period',
                'users' => ['total', 'new_in_period'],
                'health_records' => ['total', 'in_period', 'warning_distribution'],
                'personal_medical_records' => ['total'],
                'integration' => ['status_distribution'],
                'activity_logs' => ['count_in_period'],
            ],
        ]);
});

test('admin dashboard accepts period parameter', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/admin/dashboard?period=week')
        ->assertOk()
        ->assertJsonPath('data.period', 'week');

    $this->getJson('/api/admin/dashboard?period=3months')
        ->assertOk()
        ->assertJsonPath('data.period', '3months');
});

// ---------------------------------------------------------------------------
// Admin user management
// ---------------------------------------------------------------------------

test('admin can list users and response contains no PII', function () {
    $user = regularUser();
    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/users');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['user_id', 'name', 'email', 'role', 'created_at'],
                ],
            ],
        ]);

    // Sensitive fields must NOT be present in any user object
    $responseBody = $response->json('data.data');
    foreach ($responseBody as $item) {
        expect($item)->not->toHaveKey('password');
        expect($item)->not->toHaveKey('nik');
        expect($item)->not->toHaveKey('phone');
        expect($item)->not->toHaveKey('nik_hash');
        expect($item)->not->toHaveKey('google_sub');
        expect($item)->not->toHaveKey('avatar_path');
    }
});

test('admin can retrieve a single user', function () {
    $user = regularUser();
    Sanctum::actingAs(adminUser());

    $this->getJson("/api/admin/users/{$user->user_id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $user->user_id)
        ->assertJsonPath('data.email', $user->email);
});

test('admin gets 404 for non-existent user', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/admin/users/999999999')->assertNotFound();
});

test('admin can view health records for a specific user', function () {
    $user = regularUser();
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    HealthRecord::factory()->count(3)->create([
        'user_id' => $user->user_id,
        'recorded_at' => Carbon::now(),
    ]);

    Sanctum::actingAs(adminUser());

    $this->getJson("/api/admin/users/{$user->user_id}/health-records")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['data', 'total', 'per_page', 'current_page'],
        ]);
});

test('admin can view personal medical records for a specific user', function () {
    $user = regularUser();

    PersonalMedicalRecord::factory()->count(2)->create([
        'user_id' => $user->user_id,
    ]);

    Sanctum::actingAs(adminUser());

    $this->getJson("/api/admin/users/{$user->user_id}/personal-medical-records")
        ->assertOk()
        ->assertJsonPath('success', true);
});

// ---------------------------------------------------------------------------
// Activity log monitoring
// ---------------------------------------------------------------------------

test('admin can list activity logs', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/admin/activity-logs')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['data', 'total', 'per_page', 'current_page'],
        ]);
});

test('activity log is created when a user logs in', function () {
    $user = regularUser();
    $user->forceFill(['password' => bcrypt('Password123!')])->save();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'Password123!',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->user_id,
        'action' => 'LOGIN',
    ]);
});

test('activity log is created when a user creates a health record', function () {
    $user = regularUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/health-records', [
        'height' => 170,
        'weight' => 65,
        'recorded_at' => '2026-10-20T08:00:00+07:00',
    ])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->user_id,
        'action' => 'HEALTH_RECORD_CREATE',
    ]);
});

// ---------------------------------------------------------------------------
// Integration log monitoring
// ---------------------------------------------------------------------------

test('admin can list integration logs', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/admin/integration-logs')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['data', 'total', 'per_page', 'current_page'],
        ]);
});

// ---------------------------------------------------------------------------
// OpenMRS config management
// ---------------------------------------------------------------------------

test('admin can get openmrs config and credential is not returned', function () {
    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/openmrs/config');

    // 200 (config exists) or 404 (no config) — both are valid
    expect($response->status())->toBeIn([200, 404]);

    if ($response->status() === 200) {
        $data = $response->json('data');
        expect($data)->not->toHaveKey('credential');
        expect($data)->not->toHaveKey('password');
    }
});

test('admin can update openmrs config and credential is not returned in response', function () {
    Sanctum::actingAs(adminUser());

    $response = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://openmrs-test.example.com',
        'credential' => [
            'username' => 'admin',
            'password' => 'Admin1234!',
        ],
        'is_active' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data)->not->toHaveKey('credential');
    expect($data)->not->toHaveKey('password');
    expect($data['endpoint_url'])->toBe('http://openmrs-test.example.com');
});

test('openmrs config update validates required fields', function () {
    Sanctum::actingAs(adminUser());

    $this->putJson('/api/admin/openmrs/config', [])
        ->assertUnprocessable();
});

test('openmrs config update rejects invalid url', function () {
    Sanctum::actingAs(adminUser());

    $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'not-a-url',
        'credential' => ['username' => 'u', 'password' => 'p'],
    ])->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Ownership isolation — USER can only see own data, not others
// ---------------------------------------------------------------------------

test('a regular user cannot access another user health records via admin endpoint', function () {
    $other = regularUser();
    Sanctum::actingAs(regularUser()); // different user, USER role

    $this->getJson("/api/admin/users/{$other->user_id}/health-records")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// System logs endpoint
// ---------------------------------------------------------------------------

test('admin can access system logs endpoint', function () {
    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/system-logs');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['log_exists'],
        ]);
});

test('guest cannot access system logs', function () {
    $this->getJson('/api/admin/system-logs')->assertUnauthorized();
});

test('user role cannot access system logs', function () {
    Sanctum::actingAs(regularUser());
    $this->getJson('/api/admin/system-logs')->assertForbidden();
});

test('system logs endpoint rejects invalid level filter', function () {
    Sanctum::actingAs(adminUser());

    $this->getJson('/api/admin/system-logs?level=invalid_level')
        ->assertUnprocessable();
});

test('system logs endpoint accepts valid level filters', function () {
    Sanctum::actingAs(adminUser());

    foreach (['error', 'warning', 'info', 'debug'] as $level) {
        $this->getJson("/api/admin/system-logs?level={$level}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
});

// ---------------------------------------------------------------------------
// Activity logging — photo upload/delete
// ---------------------------------------------------------------------------

test('activity log is created when user logs out', function () {
    $user = regularUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/logout')->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->user_id,
        'action' => 'LOGOUT',
    ]);
});

test('activity log is created when user updates profile', function () {
    $user = regularUser();
    Sanctum::actingAs($user);

    $this->patchJson('/api/profile', [
        'name' => 'New Name',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->user_id,
        'action' => 'PROFILE_UPDATE',
    ]);
});

test('activity log is created when user deletes a health record', function () {
    $user = regularUser();
    $record = HealthRecord::factory()->create([
        'user_id' => $user->user_id,
        'recorded_at' => Carbon::now(),
    ]);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/health-records/{$record->health_record_id}")->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->user_id,
        'action' => 'HEALTH_RECORD_DELETE',
    ]);
});

// ---------------------------------------------------------------------------
// OpenMRS config — activity log on change
// ---------------------------------------------------------------------------

test('openmrs config update is logged in activity logs', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://openmrs-logging-test.example.com',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->user_id,
        'action' => 'OPENMRS_CONFIG_UPDATE',
    ]);
});

// ---------------------------------------------------------------------------
// Admin dashboard — verify data counts are real
// ---------------------------------------------------------------------------

test('admin dashboard user counts reflect actual database state', function () {
    // Create exactly 3 users with USER role within the current month
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);
    User::factory()->count(3)->for($role, 'role')->create();

    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/dashboard?period=month');

    $response->assertOk();

    // Total users must be >= 3 (may include seed data)
    $total = $response->json('data.users.total');
    expect($total)->toBeGreaterThanOrEqual(3);
});

// ---------------------------------------------------------------------------
// User list search & filter
// ---------------------------------------------------------------------------

test('admin user list supports search by name', function () {
    $uniqueName = 'UniqueAdminTestUser'.uniqid();
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);
    User::factory()->for($role, 'role')->create(['name' => $uniqueName]);

    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/users?search='.urlencode($uniqueName));

    $response->assertOk();

    $found = collect($response->json('data.data'))->firstWhere('name', $uniqueName);
    expect($found)->not->toBeNull();
});

test('admin user list supports filter by role', function () {
    Sanctum::actingAs(adminUser());

    $response = $this->getJson('/api/admin/users?role=ADMIN');

    $response->assertOk();

    // All returned users should have ADMIN role
    foreach ($response->json('data.data') as $item) {
        expect($item['role'])->toBe('ADMIN');
    }
});

// ---------------------------------------------------------------------------
// OpenMRS SSRF Protection & Hardening
// ---------------------------------------------------------------------------

test('openmrs config rejects non-https url when strict ssrf / production is enforced', function () {
    config(['services.openmrs.strict_ssrf' => true]);

    Sanctum::actingAs(adminUser());

    $response = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://openmrs.example.com',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint_url']);
});

test('openmrs config rejects cloud metadata IP 169.254.169.254 in strict mode', function () {
    config(['services.openmrs.strict_ssrf' => true]);

    Sanctum::actingAs(adminUser());

    $response = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'https://169.254.169.254/latest/meta-data',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint_url']);
});

test('openmrs config rejects localhost and loopback in strict mode', function () {
    config(['services.openmrs.strict_ssrf' => true]);

    Sanctum::actingAs(adminUser());

    $response = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'https://localhost:8443/openmrs',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint_url']);
});

test('openmrs config enforces host allowlist when configured', function () {
    config([
        'services.openmrs.allowed_hosts' => ['openmrs.trusted-hospital.org'],
    ]);

    Sanctum::actingAs(adminUser());

    // Disallowed host should be rejected
    $disallowed = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://untrusted-host.example.com/openmrs',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ]);
    $disallowed->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint_url']);

    // Allowed host should be accepted
    $allowed = $this->putJson('/api/admin/openmrs/config', [
        'endpoint_url' => 'http://openmrs.trusted-hospital.org/openmrs',
        'credential' => ['username' => 'admin', 'password' => 'secret'],
    ]);
    $allowed->assertOk();
});

test('openmrs test-connection rejects disallowed addresses with 422', function () {
    config(['services.openmrs.strict_ssrf' => true]);

    OpenmrsConfig::query()->create([
        'endpoint_url' => 'https://169.254.169.254/metadata',
        'credential' => json_encode(['username' => 'admin', 'password' => 'secret']),
        'is_active' => true,
    ]);

    Sanctum::actingAs(adminUser());

    $response = $this->postJson('/api/admin/openmrs/test-connection');

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('openmrs test-connection handles connection timeout cleanly with 503', function () {
    OpenmrsConfig::query()->create([
        'endpoint_url' => 'http://openmrs-timeout.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'secret']),
        'is_active' => true,
    ]);

    Http::fake([
        'http://openmrs-timeout.example.com/*' => function () {
            throw new ConnectionException('Connection timed out after 5000 milliseconds');
        },
    ]);

    Sanctum::actingAs(adminUser());

    $response = $this->postJson('/api/admin/openmrs/test-connection');

    $response->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.connected', false);

    // Ensure credentials or internal cURL trace are NOT leaked in response
    $content = $response->getContent();
    expect($content)->not->toContain('secret');
    expect($content)->not->toContain('cURL');

    // Ensure integration log recorded TIMEOUT
    $this->assertDatabaseHas('integration_logs', [
        'operation' => 'CONNECTION_TEST',
        'status' => 'TIMEOUT',
    ]);
});

// ---------------------------------------------------------------------------
// Activity Audit Tests for Admin Data Access
// ---------------------------------------------------------------------------

test('admin viewing user detail creates activity log without leaking pii', function () {
    $targetUser = regularUser();
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->getJson("/api/admin/users/{$targetUser->user_id}")->assertOk();

    $log = ActivityLog::query()
        ->where('user_id', $admin->user_id)
        ->where('action', 'ADMIN_USER_VIEW')
        ->latest('activity_id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->description)->not->toContain('password');
    expect($log->description)->not->toContain('token');
});

test('admin viewing health records creates activity log', function () {
    $targetUser = regularUser();
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->getJson("/api/admin/users/{$targetUser->user_id}/health-records")->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->user_id,
        'action' => 'ADMIN_HEALTH_RECORDS_VIEW',
    ]);
});

test('admin viewing personal medical records creates activity log', function () {
    $targetUser = regularUser();
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->getJson("/api/admin/users/{$targetUser->user_id}/personal-medical-records")->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->user_id,
        'action' => 'ADMIN_PERSONAL_RECORDS_VIEW',
    ]);
});

test('admin viewing system logs creates activity log', function () {
    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/system-logs')->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->user_id,
        'action' => 'ADMIN_SYSTEM_LOGS_VIEW',
    ]);
});

test('admin testing openmrs connection creates activity log', function () {
    OpenmrsConfig::query()->create([
        'endpoint_url' => 'http://openmrs-test.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'secret']),
        'is_active' => true,
    ]);

    Http::fake([
        'http://openmrs-test.example.com/*' => Http::response(['authenticated' => true], 200),
    ]);

    $admin = adminUser();
    Sanctum::actingAs($admin);

    $this->postJson('/api/admin/openmrs/test-connection')->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->user_id,
        'action' => 'ADMIN_OPENMRS_TEST_CONNECTION',
    ]);
});

// ---------------------------------------------------------------------------
// Rate Limiting Tests
// ---------------------------------------------------------------------------

test('admin test connection endpoint is throttled and returns 429 when limit exceeded', function () {
    OpenmrsConfig::query()->create([
        'endpoint_url' => 'http://openmrs-throttle.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'secret']),
        'is_active' => true,
    ]);

    Http::fake([
        'http://openmrs-throttle.example.com/*' => Http::response(['authenticated' => true], 200),
    ]);

    $admin = adminUser();
    Sanctum::actingAs($admin);

    // Limit is 10 requests per minute
    $got429 = false;
    for ($i = 0; $i < 12; $i++) {
        $response = $this->postJson('/api/admin/openmrs/test-connection');
        if ($response->status() === 429) {
            $got429 = true;
            break;
        }
    }

    expect($got429)->toBeTrue();
});
