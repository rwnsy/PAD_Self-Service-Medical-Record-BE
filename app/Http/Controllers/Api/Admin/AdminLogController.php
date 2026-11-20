<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminLogController extends Controller
{
    /**
     * Category → activity_log action name mappings.
     *
     * @var array<string, list<string>>
     */
    private const CATEGORY_ACTIONS = [
        'LOGIN' => ['LOGIN', 'LOGOUT'],
        'DATA' => [
            'PROFILE_UPDATE',
            'PROFILE_PHOTO_UPLOAD',
            'PROFILE_PHOTO_DELETE',
            'HEALTH_RECORD_CREATE',
            'HEALTH_RECORD_UPDATE',
            'HEALTH_RECORD_DELETE',
            'PERSONAL_RECORD_CREATE',
            'PERSONAL_RECORD_UPDATE',
            'PERSONAL_RECORD_DELETE',
        ],
        'SYSTEM' => [
            'OPENMRS_CONFIG_UPDATE',
            'ADMIN_OPENMRS_TEST_CONNECTION',
            'ADMIN_USER_STATUS_CHANGE',
            'ADMIN_SESSION_REVOKE',
            'ADMIN_SESSION_REVOKE_ALL',
            'ADMIN_USER_VIEW',
            'ADMIN_HEALTH_RECORDS_VIEW',
            'ADMIN_PERSONAL_RECORDS_VIEW',
            'ADMIN_SYSTEM_LOGS_VIEW',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $category = strtoupper((string) $request->query('category', 'ALL'));
        $search = $request->query('search');
        $userId = $request->query('user_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $validCategories = ['ALL', 'LOGIN', 'DATA', 'SYSTEM', 'ERROR'];
        if (! in_array($category, $validCategories, true)) {
            $category = 'ALL';
        }

        $activityEntries = collect();
        $errorEntries = collect();

        // --- Activity logs (LOGIN / DATA / SYSTEM / ALL) ---
        if ($category !== 'ERROR') {
            $actions = $category === 'ALL'
                ? array_merge(...array_values(self::CATEGORY_ACTIONS))
                : (self::CATEGORY_ACTIONS[$category] ?? []);

            $activityQuery = ActivityLog::query()
                ->with('user:user_id,name,email')
                ->when(! empty($actions), fn ($q) => $q->whereIn('action', $actions))
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay()))
                ->when($dateTo, fn ($q) => $q->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay()))
                ->when($search, fn ($q) => $q->where('description', 'ilike', "%{$search}%"))
                ->orderByDesc('created_at')
                ->limit(500) // hard cap to avoid memory issues on ALL
                ->get();

            $activityEntries = $activityQuery->map(fn (ActivityLog $log) => [
                'id' => $log->activity_id,
                'timestamp' => $log->created_at?->toIso8601String(),
                'actor' => $log->user?->name ?? 'System',
                'actor_email' => $log->user?->email,
                'category' => $this->resolveCategory($log->action),
                'level' => 'INFO',
                'action' => $log->action,
                'description' => $log->description,
                'source' => 'activity_logs',
            ]);
        }

        // --- Integration logs (ERROR category — failed/timeout entries) ---
        if ($category === 'ALL' || $category === 'ERROR') {
            $integrationQuery = IntegrationLog::query()
                ->with('user:user_id,name,email')
                ->whereIn('status', ['ERROR', 'TIMEOUT', 'FAILED'])
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->when($dateFrom, fn ($q) => $q->where('attempted_at', '>=', Carbon::parse($dateFrom)->startOfDay()))
                ->when($dateTo, fn ($q) => $q->where('attempted_at', '<=', Carbon::parse($dateTo)->endOfDay()))
                ->when($search, fn ($q) => $q->where('error_message', 'ilike', "%{$search}%"))
                ->orderByDesc('attempted_at')
                ->limit(200)
                ->get();

            $errorEntries = $integrationQuery->map(fn (IntegrationLog $log) => [
                'id' => 'integration_'.$log->log_id,
                'timestamp' => $log->attempted_at?->toIso8601String(),
                'actor' => $log->user?->name ?? 'System',
                'actor_email' => $log->user?->email,
                'category' => 'ERROR',
                'level' => $log->status === 'TIMEOUT' ? 'WARNING' : 'ERROR',
                'action' => $log->operation,
                'description' => $log->error_message ?? "Integration {$log->operation} {$log->status}",
                'source' => 'integration_logs',
            ]);
        }

        // Merge and sort by timestamp descending
        $merged = $activityEntries
            ->concat($errorEntries)
            ->sortByDesc('timestamp')
            ->values();

        // Manual pagination over merged result
        $page = max(1, (int) $request->query('page', 1));
        $total = $merged->count();
        $items = $merged->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'message' => 'Logs retrieved successfully.',
            'data' => [
                'category' => $category,
                'items' => $items,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil($total / $perPage),
                ],
            ],
        ]);
    }

    private function resolveCategory(string $action): string
    {
        foreach (self::CATEGORY_ACTIONS as $category => $actions) {
            if (in_array($action, $actions, true)) {
                return $category;
            }
        }

        return 'SYSTEM';
    }
}
