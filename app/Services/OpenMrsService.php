<?php

namespace App\Services;

use App\Models\IntegrationLog;
use App\Models\OpenmrsConfig;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenMrsService
{
    /**
     * Ambil konfigurasi OpenMRS aktif dari database.
     * Lempar RuntimeException jika tidak ada konfigurasi aktif.
     */
    public function getActiveConfig(): OpenmrsConfig
    {
        $config = OpenmrsConfig::query()->where('is_active', true)->first();

        if ($config === null) {
            throw new RuntimeException('OpenMRS integration is not configured.');
        }

        return $config;
    }

    /**
     * Cari UUID pasien OpenMRS berdasarkan identifier yang sudah dipetakan ke user.
     * Saat ini menggunakan NIK sebagai identifier utama.
     * Kembalikan null jika pasien tidak ditemukan.
     */
    public function findPatientUuid(OpenmrsConfig $config, User $user): ?string
    {
        // Credential disimpan terenkripsi sebagai JSON: {"username":"...","password":"..."}
        $credential = json_decode((string) $config->credential, true);

        if (! is_array($credential) || ! isset($credential['username'], $credential['password'])) {
            throw new RuntimeException('OpenMRS credential format is invalid.');
        }

        // Gunakan NIK sebagai identifier utama untuk lookup pasien
        $nik = $user->nik; // accessor mendekripsi otomatis
        if (! is_string($nik) || $nik === '') {
            return null;
        }

        try {
            $response = Http::withBasicAuth($credential['username'], $credential['password'])
                ->timeout(10)
                ->get(rtrim($config->endpoint_url, '/').'/ws/rest/v1/patient', [
                    'identifier' => $nik,
                    'v' => 'simple',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenMRS connection failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('OpenMRS patient lookup returned status '.$response->status());
        }

        $results = $response->json('results', []);

        // Beberapa konfigurasi OpenMRS REST API menggunakan parameter 'q' untuk mencari identifier
        if (empty($results)) {
            try {
                $fallback = Http::withBasicAuth($credential['username'], $credential['password'])
                    ->timeout(10)
                    ->get(rtrim($config->endpoint_url, '/').'/ws/rest/v1/patient', [
                        'q' => $nik,
                        'v' => 'simple',
                    ]);

                if ($fallback->successful()) {
                    $results = $fallback->json('results', []);
                }
            } catch (ConnectionException $e) {
                // Biarkan results tetap kosong jika fallback timeout
            }
        }

        if (empty($results)) {
            return null;
        }

        return $results[0]['uuid'] ?? null;
    }

    /**
     * Ambil riwayat kunjungan (encounters) pasien dari OpenMRS.
     * Kembalikan array kunjungan yang sudah di-format.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPatientEncounters(OpenmrsConfig $config, string $patientUuid, int $page = 1, int $perPage = 15): array
    {
        $credential = json_decode((string) $config->credential, true);

        if (! is_array($credential) || ! isset($credential['username'], $credential['password'])) {
            throw new RuntimeException('OpenMRS credential format is invalid.');
        }

        $startIndex = ($page - 1) * $perPage;

        try {
            $response = Http::withBasicAuth($credential['username'], $credential['password'])
                ->timeout(15)
                ->get(rtrim($config->endpoint_url, '/').'/ws/rest/v1/encounter', [
                    'patient' => $patientUuid,
                    'v' => 'full',
                    'startIndex' => $startIndex,
                    'limit' => $perPage,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenMRS connection failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('OpenMRS encounter fetch returned status '.$response->status());
        }

        $encounters = $response->json('results', []);

        return array_map(fn ($encounter) => $this->formatEncounter($encounter), $encounters);
    }

    /**
     * Format satu encounter OpenMRS menjadi struktur yang aman untuk dikembalikan ke client.
     * Tidak menyertakan data internal OpenMRS yang tidak relevan.
     *
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    private function formatEncounter(array $encounter): array
    {
        $location = $encounter['location'] ?? null;
        $obs = $encounter['obs'] ?? [];

        // Kelompokkan obs berdasarkan konsep — hanya tampilkan label dan nilai teks
        $observations = array_map(fn ($ob) => [
            'concept' => $ob['concept']['display'] ?? null,
            'value' => $ob['value']['display'] ?? (is_scalar($ob['value'] ?? null) ? $ob['value'] : null),
        ], is_array($obs) ? $obs : []);

        // Ekstraksi diagnosis: dari key diagnoses atau dari obs yang berkonsep Diagnosis
        $diagnosis = null;
        if (! empty($encounter['diagnoses']) && is_array($encounter['diagnoses'])) {
            $diagnosisNames = array_map(function ($d) {
                return $d['diagnosis']['coded']['display'] ?? ($d['display'] ?? null);
            }, $encounter['diagnoses']);
            $diagnosis = implode(', ', array_filter($diagnosisNames));
        }
        if (empty($diagnosis)) {
            foreach ($observations as $observation) {
                $concept = strtolower((string) ($observation['concept'] ?? ''));
                if (str_contains($concept, 'diagnosis') || str_contains($concept, 'diagnosa')) {
                    $diagnosis = (string) ($observation['value'] ?? '');
                    break;
                }
            }
        }

        // Ekstraksi keluhan: dari obs dengan konsep Complaint / Keluhan
        $complaint = null;
        foreach ($observations as $observation) {
            $concept = strtolower((string) ($observation['concept'] ?? ''));
            if (str_contains($concept, 'complaint') || str_contains($concept, 'keluhan')) {
                $complaint = (string) ($observation['value'] ?? '');
                break;
            }
        }

        // Ambil daftar obat dari orders jika ada
        $orders = $encounter['orders'] ?? [];
        $medications = array_map(fn ($order) => [
            'drug' => $order['drug']['display'] ?? ($order['concept']['display'] ?? null),
            'dose' => $order['dose'] ?? null,
            'dose_units' => $order['doseUnits']['display'] ?? null,
        ], is_array($orders) ? array_filter($orders, fn ($o) => ($o['type'] ?? '') === 'drugorder') : []);

        $facilityName = $location ? ($location['display'] ?? null) : null;

        return [
            'encounter_uuid' => $encounter['uuid'] ?? null,
            'encounter_type' => $encounter['encounterType']['display'] ?? null,
            'visit_date' => isset($encounter['encounterDatetime'])
                ? substr((string) $encounter['encounterDatetime'], 0, 10)
                : null,
            'facility_name' => $facilityName,
            'location' => $facilityName,
            'diagnosis' => $diagnosis ?: null,
            'complaint' => $complaint ?: null,
            'observations' => $observations,
            'medications' => $medications,
        ];
    }

    /**
     * Catat hasil integrasi ke integration_logs.
     */
    public function logIntegration(User $user, string $operation, string $status, ?string $errorMessage = null): void
    {
        IntegrationLog::create([
            'user_id' => $user->user_id,
            'operation' => $operation,
            'status' => $status,
            'error_message' => $errorMessage,
            'attempted_at' => now(),
        ]);
    }
}
