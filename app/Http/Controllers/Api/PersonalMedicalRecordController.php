<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPersonalMedicalRecordRequest;
use App\Http\Requests\StorePersonalMedicalRecordRequest;
use App\Http\Requests\UpdatePersonalMedicalRecordRequest;
use App\Models\PersonalMedicalRecord;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalMedicalRecordController extends Controller
{
    public function index(IndexPersonalMedicalRecordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $records = $request->user()
            ->personalMedicalRecords()
            ->when(
                isset($validated['visit_date_from']),
                fn ($query) => $query->where('visit_date', '>=', $validated['visit_date_from']),
            )
            ->when(
                isset($validated['visit_date_to']),
                fn ($query) => $query->where('visit_date', '<=', $validated['visit_date_to']),
            )
            ->orderByDesc('visit_date')
            ->orderByDesc('personal_medical_record_id')
            ->paginate($validated['per_page'] ?? 15);

        return $this->success('Daftar catatan medis mandiri berhasil diambil.', $records);
    }

    public function store(StorePersonalMedicalRecordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = $request->user()->personalMedicalRecords()->create([
            ...$validated,
            'source' => 'USER_REPORTED',
        ]);

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'PERSONAL_RECORD_CREATE', "Personal medical record #{$record->personal_medical_record_id} created.");

        return $this->success('Catatan medis mandiri berhasil ditambahkan.', $record, 201);
    }

    public function show(Request $request, int $personalMedicalRecordId): JsonResponse
    {
        $record = $this->findOwnedRecord($request, $personalMedicalRecordId);

        if ($record === null) {
            return $this->failure('Catatan medis mandiri tidak ditemukan.', 404);
        }

        return $this->success('Detail catatan medis mandiri berhasil diambil.', $record);
    }

    public function update(UpdatePersonalMedicalRecordRequest $request, int $personalMedicalRecordId): JsonResponse
    {
        $record = $this->findOwnedRecord($request, $personalMedicalRecordId);

        if ($record === null) {
            return $this->failure('Catatan medis mandiri tidak ditemukan.', 404);
        }

        $record->update($request->validated());

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'PERSONAL_RECORD_UPDATE', "Personal medical record #{$personalMedicalRecordId} updated.");

        return $this->success('Catatan medis mandiri berhasil diperbarui.', $record->fresh());
    }

    public function destroy(Request $request, int $personalMedicalRecordId): JsonResponse
    {
        $record = $this->findOwnedRecord($request, $personalMedicalRecordId);

        if ($record === null) {
            return $this->failure('Catatan medis mandiri tidak ditemukan.', 404);
        }

        $record->delete();

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'PERSONAL_RECORD_DELETE', "Personal medical record #{$personalMedicalRecordId} deleted.");

        return $this->success('Catatan medis mandiri berhasil dihapus.');
    }

    private function findOwnedRecord(Request $request, int $id): ?PersonalMedicalRecord
    {
        return $request->user()
            ->personalMedicalRecords()
            ->whereKey($id)
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
