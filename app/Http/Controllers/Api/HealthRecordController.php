<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexHealthRecordRequest;
use App\Http\Requests\StoreHealthRecordRequest;
use App\Http\Requests\UpdateHealthRecordRequest;
use App\Models\HealthRecord;
use App\Services\SelfAssessmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthRecordController extends Controller
{
    public function index(IndexHealthRecordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $healthRecords = $request->user()
            ->healthRecords()
            ->when(
                isset($validated['recorded_from']),
                fn ($query) => $query->where(
                    'recorded_at',
                    '>=',
                    Carbon::parse($validated['recorded_from'], 'Asia/Jakarta')->startOfDay()->utc(),
                ),
            )
            ->when(
                isset($validated['recorded_to']),
                fn ($query) => $query->where(
                    'recorded_at',
                    '<',
                    Carbon::parse($validated['recorded_to'], 'Asia/Jakarta')->startOfDay()->addDay()->utc(),
                ),
            )
            ->orderByDesc('recorded_at')
            ->paginate($validated['per_page'] ?? 15);

        return $this->success('Health record history retrieved successfully.', $healthRecords);
    }

    public function store(StoreHealthRecordRequest $request, SelfAssessmentService $selfAssessment): JsonResponse
    {
        $validated = $request->validated();

        $calculated = $selfAssessment->calculate([
            'height' => $validated['height'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'systolic' => $validated['systolic'] ?? null,
            'diastolic' => $validated['diastolic'] ?? null,
        ]);

        $bmiFullPrecision = $calculated['_bmi_full_precision'];
        unset($calculated['_bmi_full_precision']);

        $healthRecord = $request->user()->healthRecords()->create([
            ...$validated,
            ...$calculated,
            'bmi_category' => $selfAssessment->bmiCategory($bmiFullPrecision),
        ]);

        return $this->success('Health record created successfully.', $healthRecord, 201);
    }

    public function show(Request $request, int $healthRecordId): JsonResponse
    {
        $healthRecord = $this->findOwnedHealthRecord($request, $healthRecordId);

        if ($healthRecord === null) {
            return $this->failure('Health record not found.', 404);
        }

        return $this->success('Health record retrieved successfully.', $healthRecord);
    }

    public function update(
        UpdateHealthRecordRequest $request,
        int $healthRecordId,
        SelfAssessmentService $selfAssessment,
    ): JsonResponse {
        $healthRecord = $this->findOwnedHealthRecord($request, $healthRecordId);

        if ($healthRecord === null) {
            return $this->failure('Health record not found.', 404);
        }

        $validated = $request->validated();

        $calculated = $selfAssessment->calculate([
            'height' => $validated['height'] ?? $healthRecord->height,
            'weight' => $validated['weight'] ?? $healthRecord->weight,
            'systolic' => $validated['systolic'] ?? $healthRecord->systolic,
            'diastolic' => $validated['diastolic'] ?? $healthRecord->diastolic,
        ]);

        $bmiFullPrecision = $calculated['_bmi_full_precision'];
        unset($calculated['_bmi_full_precision']);

        $healthRecord->update([
            ...$validated,
            ...$calculated,
            'bmi_category' => $selfAssessment->bmiCategory($bmiFullPrecision),
        ]);

        return $this->success('Health record updated successfully.', $healthRecord->fresh());
    }

    public function destroy(Request $request, int $healthRecordId): JsonResponse
    {
        $healthRecord = $this->findOwnedHealthRecord($request, $healthRecordId);

        if ($healthRecord === null) {
            return $this->failure('Health record not found.', 404);
        }

        $healthRecord->delete();

        return $this->success('Health record deleted successfully.');
    }

    private function findOwnedHealthRecord(Request $request, int $healthRecordId): ?HealthRecord
    {
        return $request->user()
            ->healthRecords()
            ->whereKey($healthRecordId)
            ->first();
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function failure(string $message, int $status, mixed $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
