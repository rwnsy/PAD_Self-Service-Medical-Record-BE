<?php

namespace App\Console\Commands;

use App\Services\OpenMrsService;
use App\Services\OpenMrsUrlValidator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpenMrsExplore extends Command
{
    protected $signature = 'openmrs:explore
        {--patient-uuid= : UUID pasien uji (opsional; tanpa ini, probe pasien dan visit dilewati)}';

    protected $description = 'Eksplorasi READ-ONLY resource OpenMRS yang belum dicakup openmrs:probe. Mencetak status HTTP, nama field, dan metadata concept. Tidak pernah mencetak data pasien atau kredensial.';

    private string $base = '';

    /** @var array{username: string, password: string} */
    private array $credential = ['username' => '', 'password' => ''];

    private bool $authFailed = false;

    public function handle(OpenMrsService $openMrs, OpenMrsUrlValidator $validator): int
    {
        $patientUuid = $this->option('patient-uuid');

        if ($patientUuid !== null && ! preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', (string) $patientUuid)) {
            $this->error('--patient-uuid bukan UUID yang valid.');

            return self::INVALID;
        }

        try {
            $config = $openMrs->getActiveConfig();
        } catch (RuntimeException) {
            $this->error('Tidak ada konfigurasi OpenMRS aktif.');

            return self::FAILURE;
        }

        $credential = json_decode((string) $config->credential, true);
        if (! is_array($credential) || ! isset($credential['username'], $credential['password'])) {
            $this->error('Format kredensial pada konfigurasi tidak valid.');

            return self::FAILURE;
        }

        try {
            $validator->validate($config->endpoint_url);
        } catch (InvalidArgumentException) {
            $this->error('Endpoint ditolak oleh kebijakan SSRF/URL.');

            return self::FAILURE;
        }

        $this->base = rtrim($config->endpoint_url, '/');
        $this->credential = [
            'username' => (string) $credential['username'],
            'password' => (string) $credential['password'],
        ];

        $this->probeSystemInformation();
        if ($this->authFailed) {
            return $this->abortAuth();
        }
        $this->probeLocation();
        if ($this->authFailed) {
            return $this->abortAuth();
        }
        $this->probeForm();
        if ($this->authFailed) {
            return $this->abortAuth();
        }
        $this->probeDrug();
        if ($this->authFailed) {
            return $this->abortAuth();
        }
        $this->probePagination();
        if ($this->authFailed) {
            return $this->abortAuth();
        }
        $this->probeVitalSignsConcepts();
        if ($this->authFailed) {
            return $this->abortAuth();
        }

        if ($patientUuid === null) {
            $this->line('[patient detail] SKIPPED: --patient-uuid tidak diberikan.');
            $this->line('[visit] SKIPPED: --patient-uuid tidak diberikan.');
        } else {
            $this->probePatientDetail((string) $patientUuid);
            if ($this->authFailed) {
                return $this->abortAuth();
            }
            $this->probeVisit((string) $patientUuid);
            if ($this->authFailed) {
                return $this->abortAuth();
            }
        }

        return self::SUCCESS;
    }

    private function abortAuth(): int
    {
        $this->error('HTTP 401: Autentikasi gagal. Command dihentikan agar akun tidak terkunci.');

        return self::FAILURE;
    }

    private function probeSystemInformation(): void
    {
        $r = $this->get('/ws/rest/v1/systeminformation');

        if ($r['error'] !== null) {
            $this->report('systeminformation', '/ws/rest/v1/systeminformation', $r);

            return;
        }

        /** @var array<int, string> $notes */
        $notes = [];
        $this->extractVersionKeys($r['json'] ?? [], '', $notes);

        $this->report('systeminformation', '/ws/rest/v1/systeminformation', $r, $notes ?: ['no version keys found']);
    }

    /**
     * Rekursif: ekstrak key yang cocok /version/i dan cetak nilainya.
     *
     * @param  array<int, string>  $notes
     */
    private function extractVersionKeys(mixed $data, string $prefix, array &$notes): void
    {
        if (! is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? "$prefix.$key" : (string) $key;

            if (preg_match('/version/i', (string) $key) === 1) {
                $notes[] = "$fullKey: ".(is_scalar($value) ? (string) $value : gettype($value));
            }

            if (is_array($value)) {
                $this->extractVersionKeys($value, $fullKey, $notes);
            }
        }
    }

    private function probeLocation(): void
    {
        $r = $this->get('/ws/rest/v1/location', ['limit' => 3]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];

        $this->report('location', '/ws/rest/v1/location?limit=3', $r, [
            'result_count: '.count($results),
            'fields: '.$this->fields($results[0] ?? null),
        ]);
    }

    private function probeForm(): void
    {
        $r = $this->get('/ws/rest/v1/form', ['limit' => 1]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];

        $this->report('form', '/ws/rest/v1/form?limit=1', $r, [
            'result_count: '.count($results),
            'fields: '.$this->fields($results[0] ?? null),
        ]);
    }

    private function probeDrug(): void
    {
        $r = $this->get('/ws/rest/v1/drug', ['limit' => 1]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];

        $this->report('drug', '/ws/rest/v1/drug?limit=1', $r, [
            'result_count: '.count($results),
            'fields: '.$this->fields($results[0] ?? null),
        ]);
    }

    private function probePagination(): void
    {
        $r1 = $this->get('/ws/rest/v1/concept', [
            'limit' => 2,
            'startIndex' => 0,
            'v' => 'custom:(uuid)',
            'totalCount' => 'true',
        ]);

        if ($this->authFailed) {
            $this->report('pagination', '/ws/rest/v1/concept?limit=2&startIndex=0&totalCount=true', $r1);

            return;
        }

        $results1 = is_array($r1['json']['results'] ?? null) ? $r1['json']['results'] : [];
        $totalCount = $r1['json']['totalCount'] ?? null;

        $r2 = $this->get('/ws/rest/v1/concept', [
            'limit' => 2,
            'startIndex' => 1,
            'v' => 'custom:(uuid)',
        ]);

        if ($this->authFailed) {
            $this->report('pagination', '/ws/rest/v1/concept?limit=2&startIndex=1', $r2);

            return;
        }

        $results2 = is_array($r2['json']['results'] ?? null) ? $r2['json']['results'] : [];

        $startIndexHonored = count($results1) >= 2
            && count($results2) >= 1
            && isset($results1[1]['uuid'], $results2[0]['uuid'])
            && $results1[1]['uuid'] === $results2[0]['uuid'];

        $this->report('pagination', '/ws/rest/v1/concept?limit=2&startIndex=0&totalCount=true', $r1, [
            'startIndex_honored: '.($startIndexHonored ? 'yes' : 'no'),
            'totalCount_present: '.($totalCount !== null ? 'yes ('.(is_scalar($totalCount) ? $totalCount : gettype($totalCount)).')' : 'no'),
        ]);
    }

    private function probeVitalSignsConcepts(): void
    {
        $queries = [
            'Systolic Blood Pressure',
            'Diastolic Blood Pressure',
            'Height',
            'Weight',
            'Temperature',
            'Pulse',
            'Respiratory Rate',
        ];

        foreach ($queries as $name) {
            $r = $this->get('/ws/rest/v1/concept', [
                'q' => $name,
                'limit' => 5,
                'v' => 'custom:(uuid,display,datatype:(display),units)',
            ]);

            $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
            $notes = ['query: '.$name, 'result_count: '.count($results)];

            foreach (array_slice($results, 0, 3) as $concept) {
                if (! is_array($concept)) {
                    continue;
                }

                $display = $concept['display'] ?? 'n/a';
                $datatype = $concept['datatype']['display'] ?? 'n/a';
                $units = $concept['units'] ?? 'n/a';
                $notes[] = "  concept: $display | datatype: $datatype | units: $units";
            }

            $this->report('vital-concept', "/ws/rest/v1/concept?q=$name", $r, $notes);

            if ($this->authFailed) {
                break;
            }
        }
    }

    private function probePatientDetail(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/patient/'.$patientUuid, ['v' => 'full']);

        $this->report('patient detail', '/ws/rest/v1/patient/<uuid>?v=full', $r, [
            'fields: '.$this->fields($r['json']),
        ]);
    }

    private function probeVisit(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/visit', [
            'patient' => $patientUuid,
            'v' => 'full',
            'limit' => 3,
        ]);

        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
        $first = $results[0] ?? null;

        $notes = [
            'result_count: '.count($results),
            'fields: '.$this->fields($first),
        ];

        if (is_array($first)) {
            foreach (['encounters', 'attributes', 'location', 'visitType'] as $key) {
                $notes[] = "$key: ".(array_key_exists($key, $first) ? $this->shape($first[$key]) : 'absent');
            }
        }

        $this->report('visit', '/ws/rest/v1/visit?patient=<uuid>&v=full&limit=3', $r, $notes);
    }

    /**
     * GET read-only ke OpenMRS REST. Tidak mengikuti redirect, SSL default, timeout ketat.
     *
     * @param  array<string, mixed>  $query
     * @return array{status: int|null, json: array<string, mixed>|null, error: string|null}
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(15)
                ->acceptJson()
                ->withBasicAuth($this->credential['username'], $this->credential['password'])
                ->get($this->base.$path, $query);
        } catch (ConnectionException) {
            return ['status' => null, 'json' => null, 'error' => 'connection_failed'];
        }

        $json = $response->json();
        if ($response->status() === 401) {
            $this->authFailed = true;
        }

        $error = match (true) {
            $response->redirect() => 'http_3xx',
            ! $response->successful() => 'http_'.$response->status(),
            ! is_array($json) => 'non_json_response',
            default => null,
        };

        return ['status' => $response->status(), 'json' => is_array($json) ? $json : null, 'error' => $error];
    }

    /**
     * @param  array{status: int|null, json: array<string, mixed>|null, error: string|null}  $r
     * @param  array<int, string>  $notes
     */
    private function report(string $resource, string $path, array $r, array $notes = []): void
    {
        $this->line(sprintf(
            '[%s] GET %s -> HTTP %s | %s',
            $resource,
            $path,
            $r['status'] ?? 'n/a',
            $r['error'] === null ? 'ACCESS OK' : 'FAILED ('.$r['error'].')'
        ));

        foreach ($notes as $note) {
            $this->line('    '.$note);
        }
    }

    private function fields(mixed $value): string
    {
        return is_array($value) && $value !== [] ? implode(', ', array_keys($value)) : 'n/a';
    }

    private function shape(mixed $value): string
    {
        if (! is_array($value)) {
            return gettype($value);
        }

        if (array_is_list($value)) {
            return 'list('.count($value).')'.(isset($value[0]) && is_array($value[0]) ? ' of {'.$this->fields($value[0]).'}' : '');
        }

        return '{'.$this->fields($value).'}';
    }
}
