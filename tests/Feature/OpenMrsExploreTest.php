<?php

use App\Models\OpenmrsConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

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

test('openmrs:explore only sends GET requests and output does not leak credentials', function () {
    OpenmrsConfig::create([
        'endpoint_url' => 'http://openmrs-explore-test.example.com',
        'credential' => json_encode(['username' => 'explore-secret-user', 'password' => 'explore-secret-pass']),
        'is_active' => true,
        'updated_by' => null,
    ]);

    Http::fake([
        'openmrs-explore-test.example.com/ws/rest/v1/systeminformation*' => Http::response([
            'SystemInfo' => ['SystemVersion' => '2.6.0'],
            'OpenmrsInformation' => ['OpenMRSVersion' => '2.6.0'],
        ]),
        'openmrs-explore-test.example.com/*' => Http::response([
            'results' => [['uuid' => 'fake-uuid-001', 'display' => 'Fake Item']],
        ]),
    ]);

    Artisan::call('openmrs:explore');
    $output = Artisan::output();

    // Semua request harus GET — tidak boleh POST/PUT/PATCH/DELETE
    Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));

    // Output tidak boleh mengandung kredensial atau NIK
    expect($output)->not->toContain('explore-secret-user');
    expect($output)->not->toContain('explore-secret-pass');
    expect($output)->not->toContain('3201010101');
});
