<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenMrsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class MedicalRecordController extends Controller
{
    public function __construct(private readonly OpenMrsService $openMrs) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        try {
            $config = $this->openMrs->getActiveConfig();
        } catch (RuntimeException $e) {
            return $this->failure('Layanan rekam medis klinik belum dikonfigurasi.', 503);
        }

        try {
            $patientUuid = $this->openMrs->findPatientUuid($config, $user);
        } catch (Throwable $e) {
            if ($e->getMessage() === OpenMrsService::PATIENT_AMBIGUOUS) {
                // Jangan memilih salah satu pasien: perlu ditindaklanjuti admin.
                $this->openMrs->logIntegration($user, 'patient_lookup', 'AMBIGUOUS', OpenMrsService::PATIENT_AMBIGUOUS);

                return $this->patientNotFound('PATIENT_AMBIGUOUS');
            }

            $this->openMrs->logIntegration($user, 'patient_lookup', 'ERROR', $this->errorTag($e));

            return $this->failure('Gagal terhubung ke layanan rekam medis klinik. Silakan coba beberapa saat lagi.', 503);
        }

        if ($patientUuid === null) {
            // User tidak memiliki NIK atau belum terdaftar di OpenMRS
            return $this->patientNotFound('PATIENT_NOT_FOUND');
        }

        try {
            $result = $this->openMrs->getPatientEncounters($config, $patientUuid, $page, $perPage);
            $this->openMrs->logIntegration($user, 'encounter_fetch', 'SUCCESS');
        } catch (Throwable $e) {
            $this->openMrs->logIntegration($user, 'encounter_fetch', 'ERROR', $this->errorTag($e));

            return $this->failure('Gagal mengambil data rekam medis klinik. Silakan coba beberapa saat lagi.', 503);
        }

        return $this->success('Rekam medis klinik berhasil diambil.', [
            'data' => $result['items'],
            'meta' => [
                'patient_found' => true,
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $result['has_more'],
                'source' => 'openmrs',
            ],
        ]);
    }

    /**
     * Hanya tag singkat yang dicatat ke integration_logs (mis. http_401), tidak pernah pesan mentah.
     */
    private function errorTag(Throwable $e): string
    {
        return $e instanceof RuntimeException ? $e->getMessage() : 'unexpected_error';
    }

    private function patientNotFound(string $reason): JsonResponse
    {
        return $this->success('Data rekam medis klinik tidak ditemukan untuk akun ini.', [
            'data' => [],
            'meta' => ['patient_found' => false, 'reason' => $reason],
        ]);
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function failure(string $message, int $status, mixed $data = null): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => $data], $status);
    }
}
