 <?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\ActivityLog;
use App\Models\IntegrationLog;
use App\Models\OpenmrsConfig;
use App\Models\Role;
use App\Models\SystemMetric;
use App\Models\User;
use App\Notifications\AdminNotification;
use App\Services\AdminNotificationService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helpers (file-scoped â€” only in this test file)
// ---------------------------------------------------------------------------

function makeAdmin(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'ADMIN']);

    return User::factory()->for($role, 'role')->create($attrs);
}

function makeUser(array $attrs = []): User
{
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);

    return User::factory()->for($role, 'role')->create($attrs);
}

// ===========================================================================
// A. USER ACCOUNT STATUS MANAGEMENT
// ===========================================================================

test('admin can view is_active and last_seen_at in user list', function () {
    $admin = makeAdmin();
    $user = makeUser(['is_active' => true]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/users')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['data' => [['user_id', 'is_active', 'last_seen_at']]]]);
});

test('admin can deactivate a user account', function () {
    $admin = makeAdmin();
    $user = makeUser(['is_active' => true]);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/admin/users/{$user->user_id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', false);

    expect($user->fresh()->is_active)->toBeFalse();
});

test('admin can activate a user account', function () {
    $admin = makeAdmin();
    $user = makeUser(['is_active' => false]);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/admin/users/{$user->user_id}/status", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($user->fresh()->is_active)->toBeTrue();
});

test('deactivating a user revokes their tokens', function () {
    $admin = makeAdmin();
    $user = makeUser(['is_active' => true]);
    $user->createToken('android-api'); // create a token

    Sanctum::actingAs($admin);

    expect(PersonalAccessToken::where('tokenable_id', $user->user_id)->count())->toBe(1);

    $this->patchJson("/api/admin/users/{$user->user_id}/status", ['is_active' => false])
        ->assertOk();

    expect(PersonalAccessToken::where('tokenable_id', $user->user_id)->count())->toBe(0);
});

test('admin cannot deactivate their own account', function () {
    $admin = makeAdmin();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/admin/users/{$admin->user_id}/status", ['is_active' => false])
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

test('admin cannot deactivate the last active admin', function () {
    // The protection fires when deactivating would leave 0 other active admins.
    // Setup: adminA is the ONLY active ADMIN. adminB (also ADMIN, is_active=false) makes the request.
    // Sanctum::actingAs bypasses real token auth but EnsureAccountIsActive still runs.
    // We use withoutMiddleware to bypass EnsureAccountIsActive so we can test the business rule.

    $adminA = makeAdmin(['is_active' => true]);
    $adminB = makeAdmin(['is_active' => false]); // inactive admin making the request

    $this->withoutMiddleware(EnsureAccountIsActive::class);
    Sanctum::actingAs($adminB);

    $this->patchJson("/api/admin/users/{$adminA->user_id}/status", ['is_active' => false])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Cannot deactivate the last active administrator.');
});

test('regular user cannot change account status', function () {
    $user = makeUser();
    $target = makeUser();

    Sanctum::actingAs($user);

    $this->patchJson("/api/admin/users/{$target->user_id}/status", ['is_active' => false])
        ->assertForbidden();
});

test('status endpoint returns 404 for non-existent user', function () {
    $admin = makeAdmin();
    Sanctum::actingAs($admin);

    $this->patchJson('/api/admin/users/999999/status', ['is_active' => false])
        ->assertNotFound();
});

test('admin can filter users by is_active status', function () {
    $admin = makeAdmin();
    makeUser(['is_active' => true]);
    makeUser(['is_active' => false]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/users?is_active=true')->assertOk();
    $data = $response->json('data.data');
    foreach ($data as $u) {
        expect($u['is_active'])->toBeTrue();
    }
});

test('admin dashboard includes active and inactive user counts', function () {
    $admin = makeAdmin();
    makeUser(['is_active' => true]);
    makeUser(['is_active' => false]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['data' => ['users' => ['total', 'active', 'inactive', 'new_in_period']]]);
});

// ===========================================================================
// Authentication enforcement for inactive accounts
// ===========================================================================

test('inactive user cannot login with email and password', function () {
    $role = Role::query()->firstOrCreate(['role_name' => 'USER']);
    $user = User::factory()->for($role, 'role')->create([
        'email' => 'inactive@example.com',
        'password' => Hash::make('password123'),
        'is_active' => false,
        'email_verification_pending' => false,
    ]);

    $this->postJson('/api/login', [
        'email' => 'inactive@example.com',
        'password' => 'password123',
    ])->assertForbidden()
        ->assertJsonPath('success', false);
});

test('inactive user token is rejected on protected routes', function () {
    $admin = makeAdmin();
    $user = makeUser(['is_active' => false]);

    // Token was created before deactivation
    Sanctum::actingAs($user);

    $this->getJson('/api/me')->assertForbidden();
});

// ===========================================================================
// B. LAST SEEN TRACKING
// ===========================================================================

test('last_seen_at is updated when user makes an authenticated request', function () {
    $user = makeUser(['last_seen_at' => null]);

    Sanctum::actingAs($user);

    $this->getJson('/api/me')->assertOk();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

test('last_seen_at appears in admin user detail response', function () {
    $admin = makeAdmin();
    $user = makeUser(['last_seen_at' => now()]);

    Sanctum::actingAs($admin);

    $this->getJson("/api/admin/users/{$user->user_id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['last_seen_at']]);
});

test('last_seen_at is not updated when it was set within the last 5 minutes', function () {
    $recentTime = now()->subMinutes(2);
    $user = makeUser(['last_seen_at' => $recentTime]);

    Sanctum::actingAs($user);
    $this->getJson('/api/me')->assertOk();

    // Should not have changed since it's within throttle window
    $updated = $user->fresh()->last_seen_at;
    // Allow 1 second tolerance but it shouldn't have jumped far
    expect($updated?->diffInSeconds($recentTime))->toBeLessThan(5);
});

// ===========================================================================
// C. SESSION MANAGEMENT
// ===========================================================================

test('admin can list user sessions without token hash', function () {
    $admin = makeAdmin();
    $user = makeUser();
    $user->createToken('android-device-1');
    $user->createToken('android-device-2');

    Sanctum::actingAs($admin);

    $response = $this->getJson("/api/admin/users/{$user->user_id}/sessions")
        ->assertOk()
        ->assertJsonPath('success', true);

    $sessions = $response->json('data');
    expect(count($sessions))->toBe(2);

    // Ensure token hash is never returned
    foreach ($sessions as $session) {
        expect($session)->not->toHaveKey('token');
        expect($session)->toHaveKey('token_id');
        expect($session)->toHaveKey('name');
        expect($session)->toHaveKey('last_used_at');
        expect($session)->toHaveKey('expires_at');
    }
});

test('admin can revoke a specific user session', function () {
    $admin = makeAdmin();
    $user = makeUser();
    $user->createToken('device-1');
    $token2 = $user->createToken('device-2');
    $tokenId = $token2->accessToken->id;

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/admin/users/{$user->user_id}/sessions/{$tokenId}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(PersonalAccessToken::find($tokenId))->toBeNull();
    expect($user->tokens()->count())->toBe(1);
});

test('admin can revoke all sessions for a user', function () {
    $admin = makeAdmin();
    $user = makeUser();
    $user->createToken('device-1');
    $user->createToken('device-2');
    $user->createToken('device-3');

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/admin/users/{$user->user_id}/sessions")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($user->tokens()->count())->toBe(0);
});

test('regular user cannot access session management', function () {
    $user = makeUser();
    $target = makeUser();
    $target->createToken('device-1');

    Sanctum::actingAs($user);

    $this->getJson("/api/admin/users/{$target->user_id}/sessions")->assertForbidden();
});

test('revoke session returns 404 for wrong token id', function () {
    $admin = makeAdmin();
    $user = makeUser();

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/admin/users/{$user->user_id}/sessions/999999")
        ->assertNotFound();
});

// ===========================================================================
// D. OPENMRS CONNECTION MONITORING
// ===========================================================================

test('test connection records response_time_ms in integration log', function () {
    $admin = makeAdmin();

    $config = OpenmrsConfig::create([
        'endpoint_url' => 'https://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => $admin->user_id,
    ]);

    Http::fake([
        'https://openmrs.example.com/*' => Http::response(['authenticated' => true], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/admin/openmrs/test-connection')->assertOk();

    $log = IntegrationLog::query()
        ->where('operation', 'CONNECTION_TEST')
        ->latest('attempted_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->response_data)->toHaveKey('response_time_ms');
    expect($log->response_data['response_time_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($log->response_data)->toHaveKey('connected');
    expect($log->response_data)->toHaveKey('authenticated');
    expect($log->response_data)->toHaveKey('status_code');
});

test('test connection response includes response_time_ms', function () {
    $admin = makeAdmin();

    OpenmrsConfig::create([
        'endpoint_url' => 'https://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => $admin->user_id,
    ]);

    Http::fake(['https://openmrs.example.com/*' => Http::response(['authenticated' => true], 200)]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/admin/openmrs/test-connection')
        ->assertOk()
        ->assertJsonStructure(['data' => ['connected', 'authenticated', 'status_code', 'response_time_ms']]);
});

test('credential is not exposed in integration log', function () {
    $admin = makeAdmin();

    OpenmrsConfig::create([
        'endpoint_url' => 'https://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => $admin->user_id,
    ]);

    Http::fake(['https://openmrs.example.com/*' => Http::response(['authenticated' => false], 401)]);

    Sanctum::actingAs($admin);
    $this->postJson('/api/admin/openmrs/test-connection');

    $log = IntegrationLog::query()->where('operation', 'CONNECTION_TEST')->latest('attempted_at')->first();
    $logJson = json_encode($log->toArray());

    expect($logJson)->not->toContain('Admin123');
    expect($logJson)->not->toContain('admin'); // username
});

test('openmrs timeout is recorded in integration log', function () {
    $admin = makeAdmin();

    OpenmrsConfig::create([
        'endpoint_url' => 'https://openmrs.example.com',
        'credential' => json_encode(['username' => 'admin', 'password' => 'Admin123']),
        'is_active' => true,
        'updated_by' => $admin->user_id,
    ]);

    Http::fake(fn () => throw new ConnectionException('timeout'));

    Sanctum::actingAs($admin);

    $this->postJson('/api/admin/openmrs/test-connection')->assertStatus(503);

    $log = IntegrationLog::query()->where('operation', 'CONNECTION_TEST')->where('status', 'TIMEOUT')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->response_data)->toHaveKey('response_time_ms');
});

// ===========================================================================
// E. CONSOLIDATED LOG MONITORING
// ===========================================================================

test('admin can access the consolidated logs endpoint', function () {
    $admin = makeAdmin();

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/logs')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['category', 'items', 'meta']]);
});

test('regular user cannot access consolidated logs', function () {
    $user = makeUser();
    Sanctum::actingAs($user);
    $this->getJson('/api/admin/logs')->assertForbidden();
});

test('log category filter ALL returns items', function () {
    $admin = makeAdmin();
    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/logs?category=ALL')
        ->assertOk()
        ->assertJsonPath('data.category', 'ALL');
});

test('log category filter LOGIN is applied', function () {
    $admin = makeAdmin();
    ActivityLog::create(['user_id' => $admin->user_id, 'action' => 'LOGIN', 'description' => 'User logged in.']);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/logs?category=LOGIN')->assertOk();
    expect($response->json('data.category'))->toBe('LOGIN');

    foreach ($response->json('data.items') as $item) {
        expect($item['category'])->toBe('LOGIN');
    }
});

test('log category ERROR includes failed integration logs', function () {
    $admin = makeAdmin();

    IntegrationLog::create([
        'user_id' => $admin->user_id,
        'operation' => 'CONNECTION_TEST',
        'status' => 'ERROR',
        'error_message' => 'Connection refused.',
        'attempted_at' => now(),
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/logs?category=ERROR')->assertOk();
    $items = $response->json('data.items');
    expect(count($items))->toBeGreaterThan(0);

    $found = collect($items)->firstWhere('source', 'integration_logs');
    expect($found)->not->toBeNull();
    expect($found['category'])->toBe('ERROR');
});

test('log search filters by description', function () {
    $admin = makeAdmin();
    ActivityLog::create(['user_id' => $admin->user_id, 'action' => 'LOGIN', 'description' => 'UniqueSearchTerm123']);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/logs?search=UniqueSearchTerm123')->assertOk();
    expect(count($response->json('data.items')))->toBeGreaterThan(0);
});

test('log response has correct pagination meta', function () {
    $admin = makeAdmin();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/logs?per_page=5')->assertOk();
    expect($response->json('data.meta.per_page'))->toBe(5);
    expect($response->json('data.meta'))->toHaveKey('total');
    expect($response->json('data.meta'))->toHaveKey('last_page');
});

// ===========================================================================
// F. SYSTEM HEALTH & NOTIFICATIONS
// ===========================================================================

test('system health service checks database connectivity', function () {
    $service = app(SystemHealthService::class);
    $metric = $service->checkDatabase();

    expect($metric->component)->toBe('database');
    expect($metric->status)->toBe('HEALTHY');
    expect($metric->response_time_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($metric->checked_at)->not->toBeNull();
});

test('system health service checks application', function () {
    $service = app(SystemHealthService::class);
    $metric = $service->checkApplication();

    expect($metric->component)->toBe('application');
    expect($metric->status)->toBe('HEALTHY');
});

test('system health openmrs returns UNKNOWN when no recent log', function () {
    // No integration logs exist
    $service = app(SystemHealthService::class);
    $metric = $service->checkOpenMrs();

    expect($metric->component)->toBe('openmrs');
    expect($metric->status)->toBe('UNKNOWN');
});

test('system health openmrs returns HEALTHY from recent success log', function () {
    IntegrationLog::create([
        'operation' => 'CONNECTION_TEST',
        'status' => 'SUCCESS',
        'response_data' => ['response_time_ms' => 120, 'connected' => true, 'authenticated' => true],
        'attempted_at' => now(),
    ]);

    $service = app(SystemHealthService::class);
    $metric = $service->checkOpenMrs();

    expect($metric->status)->toBe('HEALTHY');
    expect($metric->response_time_ms)->toBe(120);
});

test('system health openmrs returns DEGRADED from timeout log', function () {
    IntegrationLog::create([
        'operation' => 'CONNECTION_TEST',
        'status' => 'TIMEOUT',
        'response_data' => ['response_time_ms' => 5000],
        'attempted_at' => now(),
    ]);

    $service = app(SystemHealthService::class);
    $metric = $service->checkOpenMrs();

    expect($metric->status)->toBe('DEGRADED');
});

test('admin dashboard includes system_health data', function () {
    $admin = makeAdmin();

    // Seed a metric so health data is available
    SystemMetric::create([
        'component' => 'database',
        'status' => 'HEALTHY',
        'response_time_ms' => 5,
        'checked_at' => now(),
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['data' => ['system_health']]);
});

test('notification is not duplicated when status has not changed', function () {
    $admin = makeAdmin();

    // Seed two metrics with the same status (simulating no change)
    SystemMetric::create(['component' => 'database', 'status' => 'HEALTHY', 'checked_at' => now()->subMinute()]);
    SystemMetric::create(['component' => 'database', 'status' => 'HEALTHY', 'checked_at' => now()]);

    $notifService = app(AdminNotificationService::class);
    $notifService->notifyOnStatusChange('database', 'HEALTHY');

    // No notifications should have been sent (no status change)
    expect($admin->notifications()->count())->toBe(0);
});

test('admin can list notifications', function () {
    $admin = makeAdmin();
    $admin->notify(new AdminNotification(
        title: 'Test',
        body: 'DB is down',
        component: 'database',
        previousStatus: 'HEALTHY',
        currentStatus: 'DOWN',
    ));

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/notifications')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['data' => [['id', 'type', 'data', 'read_at', 'created_at']]]]);
});

test('admin can get unread notification count', function () {
    $admin = makeAdmin();
    $admin->notify(new AdminNotification('T', 'B', 'database', 'HEALTHY', 'DOWN'));
    $admin->notify(new AdminNotification('T2', 'B2', 'openmrs', 'HEALTHY', 'DOWN'));

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);
});

test('admin can mark a notification as read', function () {
    $admin = makeAdmin();
    $admin->notify(new AdminNotification('T', 'B', 'db', 'HEALTHY', 'DOWN'));

    $notif = $admin->unreadNotifications()->first();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/admin/notifications/{$notif->id}/read")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($notif->fresh()->read_at)->not->toBeNull();
});

test('admin can mark all notifications as read', function () {
    $admin = makeAdmin();
    $admin->notify(new AdminNotification('T1', 'B1', 'db', 'HEALTHY', 'DOWN'));
    $admin->notify(new AdminNotification('T2', 'B2', 'app', 'HEALTHY', 'DOWN'));

    Sanctum::actingAs($admin);

    $this->patchJson('/api/admin/notifications/read-all')->assertOk();

    expect($admin->unreadNotifications()->count())->toBe(0);
});

test('regular user cannot access admin notifications', function () {
    $user = makeUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/notifications')->assertForbidden();
});
