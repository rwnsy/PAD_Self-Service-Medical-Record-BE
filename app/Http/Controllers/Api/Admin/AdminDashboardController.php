<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\HealthRecord;
use App\Models\IntegrationLog;
use App\Models\PersonalMedicalRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');

        $from = match ($period) {
            'week' => Carbon::now('Asia/Jakarta')->subWeek()->startOfDay(),
            '3months' => Carbon::now('Asia/Jakarta')->subMonths(3)->startOfDay(),
            '6months' => Carbon::now('Asia/Jakarta')->subMonths(6)->startOfDay(),
            default => Carbon::now('Asia/Jakarta')->subMonth()->startOfDay(),
        };

        $totalUsers = User::query()->count();

        $newUsers = User::query()
            ->where('created_at', '>=', $from)
            ->count();

        $totalHealthRecords = HealthRecord::query()->count();

        $healthRecordsInPeriod = HealthRecord::query()
            ->where('recorded_at', '>=', $from)
            ->count();

        $warningCounts = HealthRecord::query()
            ->where('recorded_at', '>=', $from)
            ->whereNotNull('warning_status')
            ->selectRaw('warning_status, count(*) as total')
            ->groupBy('warning_status')
            ->pluck('total', 'warning_status')
            ->toArray();

        $totalPersonalRecords = PersonalMedicalRecord::query()->count();

        $integrationStats = IntegrationLog::query()
            ->where('attempted_at', '>=', $from)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $activityCount = ActivityLog::query()
            ->where('created_at', '>=', $from)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard data retrieved successfully.',
            'data' => [
                'period' => $period,
                'users' => [
                    'total' => $totalUsers,
                    'new_in_period' => $newUsers,
                ],
                'health_records' => [
                    'total' => $totalHealthRecords,
                    'in_period' => $healthRecordsInPeriod,
                    'warning_distribution' => $warningCounts,
                ],
                'personal_medical_records' => [
                    'total' => $totalPersonalRecords,
                ],
                'integration' => [
                    'status_distribution' => $integrationStats,
                ],
                'activity_logs' => [
                    'count_in_period' => $activityCount,
                ],
            ],
        ]);
    }
}
