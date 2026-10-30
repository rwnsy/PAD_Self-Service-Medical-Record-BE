<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(DashboardRequest $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->validated()['period'] ?? 'month';

        $from = match ($period) {
            'week' => Carbon::now('Asia/Jakarta')->subWeek()->startOfDay(),
            '3months' => Carbon::now('Asia/Jakarta')->subMonths(3)->startOfDay(),
            '6months' => Carbon::now('Asia/Jakarta')->subMonths(6)->startOfDay(),
            default => Carbon::now('Asia/Jakarta')->subMonth()->startOfDay(),
        };

        // Latest BMI record
        $latestBmi = $user->healthRecords()
            ->whereNotNull('height')
            ->whereNotNull('weight')
            ->orderByDesc('recorded_at')
            ->first();

        // Latest Blood Pressure record
        $latestBp = $user->healthRecords()
            ->whereNotNull('systolic')
            ->whereNotNull('diastolic')
            ->orderByDesc('recorded_at')
            ->first();

        // Latest warning record
        $latestWarning = $user->healthRecords()
            ->whereNotNull('warning_status')
            ->orderByDesc('recorded_at')
            ->first();

        // Total assessments within period
        $totalAssessments = $user->healthRecords()
            ->where('recorded_at', '>=', $from)
            ->count();

        // Last recorded date within period
        $lastRecord = $user->healthRecords()
            ->where('recorded_at', '>=', $from)
            ->orderByDesc('recorded_at')
            ->first();

        // BMI chart trend within period
        $bmiTrend = $user->healthRecords()
            ->whereNotNull('bmi')
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn ($record) => [
                'health_record_id' => $record->health_record_id,
                'bmi' => $record->bmi,
                'bmi_category' => $record->bmi_category,
                'recorded_date' => $record->recorded_date,
            ])
            ->values()
            ->all();

        // Blood pressure chart trend within period
        $bpTrend = $user->healthRecords()
            ->whereNotNull('systolic')
            ->whereNotNull('diastolic')
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn ($record) => [
                'health_record_id' => $record->health_record_id,
                'systolic' => $record->systolic,
                'diastolic' => $record->diastolic,
                'warning_status' => $record->warning_status,
                'recorded_date' => $record->recorded_date,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully.',
            'data' => [
                'period' => $period,
                'profile' => $user->only(['user_id', 'name', 'email', 'gender', 'date_of_birth']),
                'summary' => [
                    'latest_bmi' => $latestBmi ? [
                        'health_record_id' => $latestBmi->health_record_id,
                        'bmi' => $latestBmi->bmi,
                        'bmi_category' => $latestBmi->bmi_category,
                        'bmi_message' => $latestBmi->bmi_message,
                        'height' => $latestBmi->height,
                        'weight' => $latestBmi->weight,
                        'recorded_date' => $latestBmi->recorded_date,
                    ] : null,
                    'latest_blood_pressure' => $latestBp ? [
                        'health_record_id' => $latestBp->health_record_id,
                        'systolic' => $latestBp->systolic,
                        'diastolic' => $latestBp->diastolic,
                        'warning_status' => $latestBp->warning_status,
                        'warning_message' => $latestBp->warning_message,
                        'recorded_date' => $latestBp->recorded_date,
                    ] : null,
                    'latest_warning' => $latestWarning ? [
                        'warning_status' => $latestWarning->warning_status,
                        'warning_message' => $latestWarning->warning_message,
                        'recorded_date' => $latestWarning->recorded_date,
                    ] : null,
                    'total_assessments' => $totalAssessments,
                    'last_recorded_date' => $lastRecord?->recorded_date,
                ],
                'charts' => [
                    'bmi_trend' => $bmiTrend,
                    'blood_pressure_trend' => $bpTrend,
                ],
            ],
        ]);
    }
}
