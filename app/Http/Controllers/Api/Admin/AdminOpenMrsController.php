<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOpenMrsConfigRequest;
use App\Models\IntegrationLog;
use App\Models\OpenmrsConfig;
use App\Services\ActivityLogService;
use App\Services\OpenMrsUrlValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class AdminOpenMrsController extends Controller
{
    public function getConfig(): JsonResponse
    {
        $config = OpenmrsConfig::query()->latest('created_at')->first();

        if ($config === null) {
            return response()->json([
                'success' => false,
                'message' => 'No OpenMRS configuration found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OpenMRS configuration retrieved successfully.',
            'data' => [
                'config_id' => $config->config_id,
                'endpoint_url' => $config->endpoint_url,
                'is_active' => $config->is_active,
                'updated_by' => $config->updated_by,
                'created_at' => $config->created_at,
                'updated_at' => $config->updated_at,
                // credential intentionally omitted — never returned
            ],
        ]);
    }

    public function updateConfig(UpdateOpenMrsConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $config = OpenmrsConfig::query()->latest('created_at')->first();

        $credentialJson = json_encode([
            'username' => $validated['credential']['username'],
            'password' => $validated['credential']['password'],
        ]);

        $data = [
            'endpoint_url' => $validated['endpoint_url'],
            'credential' => $credentialJson,
            'updated_by' => $request->user()->user_id,
        ];

        if (isset($validated['is_active'])) {
            $data['is_active'] = $validated['is_active'];
        }

        if ($config === null) {
            $config = OpenmrsConfig::create([...$data, 'is_active' => $validated['is_active'] ?? true]);
        } else {
            $config->update($data);
            $config->refresh();
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log(
            $request->user()->user_id,
            'OPENMRS_CONFIG_UPDATE',
            "OpenMRS config updated. Endpoint: {$validated['endpoint_url']}",
        );

        return response()->json([
            'success' => true,
            'message' => 'OpenMRS configuration updated successfully.',
            'data' => [
                'config_id' => $config->config_id,
                'endpoint_url' => $config->endpoint_url,
                'is_active' => $config->is_active,
                'updated_by' => $config->updated_by,
                'updated_at' => $config->updated_at,
            ],
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_OPENMRS_TEST_CONNECTION', 'Admin tested OpenMRS connection.');

        $config = OpenmrsConfig::query()->where('is_active', true)->first();

        if ($config === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active OpenMRS configuration found.',
                'data' => null,
            ], 422);
        }

        $credential = json_decode((string) $config->credential, true);

        if (! is_array($credential) || ! isset($credential['username'], $credential['password'])) {
            return response()->json([
                'success' => false,
                'message' => 'OpenMRS credential format is invalid.',
                'data' => null,
            ], 422);
        }

        // Validate endpoint URL against SSRF policy
        try {
            app(OpenMrsUrlValidator::class)->validate($config->endpoint_url);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'OpenMRS connection test rejected: '.$e->getMessage(),
                'data' => [
                    'connected' => false,
                    'authenticated' => false,
                ],
            ], 422);
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(5)
                ->connectTimeout(3)
                ->withBasicAuth($credential['username'], $credential['password'])
                ->get(rtrim($config->endpoint_url, '/').'/ws/rest/v1/session');

            $connected = $response->successful();
            $authenticated = $connected && ($response->json('authenticated') === true);

            // Log test connection result to integration_logs (safely)
            try {
                IntegrationLog::create([
                    'user_id' => $request->user()->user_id,
                    'operation' => 'CONNECTION_TEST',
                    'status' => $authenticated ? 'SUCCESS' : 'FAILED',
                    'error_message' => $authenticated ? null : 'Authentication failed with HTTP status '.$response->status(),
                    'attempted_at' => now(),
                ]);
            } catch (Throwable) {
                // Logging must never fail the main request
            }

            return response()->json([
                'success' => $authenticated,
                'message' => $authenticated
                    ? 'OpenMRS connection test successful.'
                    : 'OpenMRS connection established but authentication failed.',
                'data' => [
                    'connected' => $connected,
                    'authenticated' => $authenticated,
                    'status_code' => $response->status(),
                ],
            ], $authenticated ? 200 : 422);
        } catch (ConnectionException) {
            try {
                IntegrationLog::create([
                    'user_id' => $request->user()->user_id,
                    'operation' => 'CONNECTION_TEST',
                    'status' => 'TIMEOUT',
                    'error_message' => 'Connection timed out or failed to reach host.',
                    'attempted_at' => now(),
                ]);
            } catch (Throwable) {
                // Logging must never fail the main request
            }

            return response()->json([
                'success' => false,
                'message' => 'OpenMRS connection test timed out or could not reach server.',
                'data' => [
                    'connected' => false,
                    'authenticated' => false,
                ],
            ], 503);
        } catch (Throwable) {
            try {
                IntegrationLog::create([
                    'user_id' => $request->user()->user_id,
                    'operation' => 'CONNECTION_TEST',
                    'status' => 'ERROR',
                    'error_message' => 'Connection test failed due to an unexpected error.',
                    'attempted_at' => now(),
                ]);
            } catch (Throwable) {
                // Logging must never fail the main request
            }

            return response()->json([
                'success' => false,
                'message' => 'OpenMRS connection test failed due to a server error.',
                'data' => [
                    'connected' => false,
                    'authenticated' => false,
                ],
            ], 503);
        }
    }
}
