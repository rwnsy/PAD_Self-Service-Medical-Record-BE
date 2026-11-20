<?php

namespace App\Services;

use App\Models\IntegrationLog;
use App\Models\OpenmrsConfig;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpenMrsService
{
    /** Tag exception ketika lebih dari satu pasien OpenMRS cocok dengan identifier. */
    public const PATIENT_AMBIGUOUS = 'patient_ambiguous';

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
     * Cari UUID pasien OpenMRS berdasarkan NIK user (identifier utama).
     *
     * Hanya pasien yang memiliki identifier (non-voided) persis sama dengan NIK yang diterima.
     * Kembalikan null jika tidak ada yang cocok; lempar PATIENT_AMBIGUOUS jika lebih dari satu pasien cocok.
     */
    public function findPatientUuid(OpenmrsConfig $config, User $user): ?string
    {
        $nik = $user->nik; // accessor mendekripsi otomatis
        if (! is_string($nik) || $nik === '') {
            return null;
        }

        $json = $this->get($config, '/ws/rest/v1/patient', [
            'identifier' => $nik,
            'v' => 'custom:(uuid,identifiers:(identifier,voided))',
        ]);

        // Server bisa mengabaikan filter atau mencocokkan sebagian: jangan percaya hasil mentah.
        $matches = array_filter($json['results'], fn ($patient) => is_array($patient) && $this->hasIdentifier($patient, $nik));
        $uuids = array_values(array_unique(array_column($matches, 'uuid')));

        if (count($uuids) > 1) {
            throw new RuntimeException(self::PATIENT_AMBIGUOUS);
        }

        return $uuids[0] ?? null;
    }

    /**
     * Ambil riwayat kunjungan (encounters) pasien dari OpenMRS, satu halaman.
     *
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function getPatientEncounters(OpenmrsConfig $config, string $patientUuid, int $page = 1, int $perPage = 15): array
    {
        $json = $this->get($config, '/ws/rest/v1/encounter', [
            'patient' => $patientUuid,
            'v' => 'full',
            'startIndex' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ]);

        $links = is_array($json['links'] ?? null) ? $json['links'] : [];

        return [
            'items' => array_map(
                fn ($encounter) => $this->formatEncounter($encounter),
                array_values(array_filter($json['results'], 'is_array')),
            ),
            'has_more' => in_array('next', array_column($links, 'rel'), true),
        ];
    }

    /**
     * @param  array<string, mixed>  $patient
     */
    private function hasIdentifier(array $patient, string $identifier): bool
    {
        $identifiers = is_array($patient['identifiers'] ?? null) ? $patient['identifiers'] : [];

        foreach ($identifiers as $item) {
            if (is_array($item) && ($item['voided'] ?? false) !== true && ($item['identifier'] ?? null) === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * GET read-only ke OpenMRS REST; hanya mengembalikan JSON yang memiliki array `results`.
     * Exception hanya membawa tag singkat (mis. `http_401`) agar URL, NIK, dan kredensial tidak ikut bocor.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(OpenmrsConfig $config, string $path, array $query): array
    {
        $credential = json_decode((string) $config->credential, true);

        if (! is_array($credential) || ! isset($credential['username'], $credential['password'])) {
            throw new RuntimeException('credential_invalid');
        }

        try {
            app(OpenMrsUrlValidator::class)->validate($config->endpoint_url);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('endpoint_rejected');
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(15)
                ->acceptJson()
                ->withBasicAuth($credential['username'], $credential['password'])
                ->get(rtrim($config->endpoint_url, '/').$path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('connection_failed');
        }

        if (! $response->successful()) {
            throw new RuntimeException('http_'.$response->status());
        }

        $json = $response->json();

        if (! is_array($json) || ! is_array($json['results'] ?? null)) {
            throw new RuntimeException('invalid_response');
        }

        return $json;
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

        // Ambil daftar obat dari orders jika ada (array_values: filter meninggalkan indeks bolong -> JSON object)
        $orders = $encounter['orders'] ?? [];
        $medications = array_values(array_map(fn ($order) => [
            'drug' => $order['drug']['display'] ?? ($order['concept']['display'] ?? null),
            'dose' => $order['dose'] ?? null,
            'dose_units' => $order['doseUnits']['display'] ?? null,
        ], is_array($orders) ? array_filter($orders, fn ($o) => ($o['type'] ?? '') === 'drugorder') : []));

        $facilityName = $location ? ($location['display'] ?? null) : null;

        return [
            'source' => 'OPENMRS',
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
