<?php

namespace App\Services;

use App\Models\IntegrationLog;
use App\Models\SystemMetric;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    /**
     * Check the database component and store a metric.
     */
    public function checkDatabase(): SystemMetric
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');
            $responseTimeMs = (int) round((microtime(true) - $start) * 1000);
            $status = 'HEALTHY';
        } catch (Throwable) {
            $responseTimeMs = null;
            $status = 'DOWN';
        }

        return SystemMetric::create([
            'component' => 'database',
            'status' => $status,
            'response_time_ms' => $responseTimeMs,
            'metadata' => null,
            'checked_at' => now('Asia/Jakarta'),
        ]);
    }

    /**
     * Check the application component (always HEALTHY if the scheduler runs).
     */
    public function checkApplication(): SystemMetric
    {
        return SystemMetric::create([
            'component' => 'application',
            'status' => 'HEALTHY',
            'response_time_ms' => null,
            'metadata' => ['php_version' => PHP_VERSION],
            'checked_at' => now('Asia/Jakarta'),
        ]);
    }

    /**
     * Derive OpenMRS status from the most recent integration_log rather than live-pinging.
     * Returns UNKNOWN when there is no recent test within the last 24 hours.
     */
    public function checkOpenMrs(): SystemMetric
    {
        $latest = IntegrationLog::query()
            ->where('operation', 'CONNECTION_TEST')
            ->orderByDesc('attempted_at')
            ->first();

        if ($latest === null || $latest->attempted_at?->diffInHours(now()) > 24) {
            $status = 'UNKNOWN';
            $responseTimeMs = null;
            $metadata = ['reason' => 'No recent connection test'];
        } else {
            $responseTimeMs = is_array($latest->response_data)
                ? ($latest->response_data['response_time_ms'] ?? null)
                : null;

            $status = match ($latest->status) {
                'SUCCESS' => 'HEALTHY',
                'TIMEOUT' => 'DEGRADED',
                default => 'DOWN',
            };

            $metadata = [
                'last_test_status' => $latest->status,
                'last_test_at' => $latest->attempted_at?->toIso8601String(),
            ];
        }

        return SystemMetric::create([
            'component' => 'openmrs',
            'status' => $status,
            'response_time_ms' => $responseTimeMs,
            'metadata' => $metadata,
            'checked_at' => now('Asia/Jakarta'),
        ]);
    }

    /**
     * Return the latest metric per component.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function getLatestMetrics(): array
    {
        $components = ['application', 'database', 'openmrs'];
        $result = [];

        foreach ($components as $component) {
            $metric = SystemMetric::query()
                ->where('component', $component)
                ->orderByDesc('checked_at')
                ->first();

            $result[$component] = $metric ? [
                'status' => $metric->status,
                'response_time_ms' => $metric->response_time_ms,
                'metadata' => $metric->metadata,
                'checked_at' => $metric->checked_at?->toIso8601String(),
            ] : null;
        }

        return $result;
    }
}
