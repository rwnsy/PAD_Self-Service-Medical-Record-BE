<?php

namespace App\Console\Commands;

use App\Services\OpenMrsService;
use App\Services\OpenMrsUrlValidator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpenMrsProbe extends Command
{
    protected $signature = 'openmrs:probe
        {--patient-uuid= : UUID pasien uji yang secara eksplisit diizinkan (untuk encounter, obs, diagnosis, order)}
        {--test-identifier= : Identifier pasien uji yang diizinkan (untuk memverifikasi filter pencarian identifier)}';

    protected $description = 'Probe READ-ONLY REST OpenMRS. Hanya mencetak status HTTP dan nama field; tidak pernah mencetak nilai data atau kredensial.';

    private string $base = '';

    /** @var array{username: string, password: string} */
    private array $credential = ['username' => '', 'password' => ''];

    public function handle(OpenMrsService $openMrs, OpenMrsUrlValidator $validator): int
    {
        $patientUuid = $this->option('patient-uuid');
        $testIdentifier = $this->option('test-identifier');

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
        $this->credential = ['username' => (string) $credential['username'], 'password' => (string) $credential['password']];

        $this->probeBase();

        if (! $this->probeSession()) {
            $this->warn('Autentikasi gagal: probe lain dilewati agar akun integrasi tidak terkunci.');

            return self::FAILURE;
        }

        $this->probeIdentifierTypes();
        $this->probePatientSearch(is_string($testIdentifier) && $testIdentifier !== '' ? $testIdentifier : null);
        $this->probeConcept();

        if ($patientUuid === null) {
            foreach (['encounter', 'obs', 'diagnosis', 'order (medication)'] as $resource) {
                $this->line("[{$resource}] SKIPPED: --patient-uuid pasien uji tidak diberikan.");
            }

            return self::SUCCESS;
        }

        $this->probeEncounter((string) $patientUuid);
        $this->probeObs((string) $patientUuid);
        $this->probeDiagnosis((string) $patientUuid);
        $this->probeMedication((string) $patientUuid);

        return self::SUCCESS;
    }

    private function probeBase(): void
    {
        $r = $this->get('/ws/rest/v1');
        $this->report('base', '/ws/rest/v1', $r, $r['error'] === 'http_3xx' ? ['Redirect tidak diikuti: endpoint_url kemungkinan belum memuat context path (mis. /openmrs).'] : []);
    }

    private function probeSession(): bool
    {
        $r = $this->get('/ws/rest/v1/session');
        $authenticated = ($r['json']['authenticated'] ?? false) === true;
        $this->report('session', '/ws/rest/v1/session', $r, [
            'authenticated: '.($authenticated ? 'yes' : 'no'),
            'fields: '.$this->fields($r['json']),
        ]);

        return $authenticated;
    }

    private function probeIdentifierTypes(): void
    {
        $r = $this->get('/ws/rest/v1/patientidentifiertype', ['v' => 'full']);
        $types = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
        $nikLike = array_filter($types, fn ($t) => is_array($t) && preg_match('/nik|ktp/i', (string) ($t['name'] ?? '')) === 1);

        $this->report('patient identifier type', '/ws/rest/v1/patientidentifiertype?v=full', $r, [
            'type_count: '.count($types),
            'nik_like_type_exists: '.($nikLike === [] ? 'no' : 'yes'),
            'nik_like_type_has_validator: '.(array_filter($nikLike, fn ($t) => ! empty($t['validator'])) === [] ? 'no' : 'yes'),
            'fields: '.$this->fields($types[0] ?? null),
        ]);
    }

    private function probePatientSearch(?string $testIdentifier): void
    {
        $path = '/ws/rest/v1/patient?identifier=<value>';

        // Kontrol: identifier mustahil ada. Jika tetap ada hasil, server mengabaikan filter dan pencarian tidak boleh dipakai.
        $control = $this->get('/ws/rest/v1/patient', ['identifier' => 'PROBE'.bin2hex(random_bytes(6)), 'v' => 'custom:(uuid)', 'limit' => 1]);
        $controlCount = count($control['json']['results'] ?? []);
        $honored = $control['error'] === null && $controlCount === 0;

        $notes = ['identifier_filter: '.($control['error'] !== null ? 'UNKNOWN' : ($honored ? 'honored (kontrol tanpa hasil)' : 'IGNORED (kontrol mengembalikan hasil) - jangan dipakai'))];

        if (! $honored || $testIdentifier === null) {
            $notes[] = $honored ? 'SKIPPED pencarian identifier uji: --test-identifier tidak diberikan.' : 'SKIPPED pencarian identifier uji.';
            $this->report('patient search (kontrol)', $path, $control, $notes);

            return;
        }

        $r = $this->get('/ws/rest/v1/patient', ['identifier' => $testIdentifier, 'v' => 'custom:(uuid,identifiers:(identifier,voided))', 'limit' => 5]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
        $exact = array_filter($results, function ($p) use ($testIdentifier) {
            foreach (is_array($p['identifiers'] ?? null) ? $p['identifiers'] : [] as $i) {
                if (is_array($i) && ($i['voided'] ?? false) !== true && ($i['identifier'] ?? null) === $testIdentifier) {
                    return true;
                }
            }

            return false;
        });

        $this->report('patient search', $path, $r, array_merge($notes, [
            'result_count: '.count($results),
            'exact_identifier_matches: '.count($exact),
            'custom_rep_identifiers: '.(isset($results[0]['identifiers']) ? 'supported' : 'not seen'),
        ]));
    }

    private function probeConcept(): void
    {
        $r = $this->get('/ws/rest/v1/concept', ['limit' => 1]);
        $this->report('concept', '/ws/rest/v1/concept?limit=1', $r, ['fields: '.$this->fields($r['json']['results'][0] ?? null)]);
    }

    private function probeEncounter(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/encounter', ['patient' => $patientUuid, 'v' => 'full', 'limit' => 5]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
        $first = $results[0] ?? null;

        $dates = array_values(array_filter(array_map(fn ($e) => is_array($e) ? strtotime((string) ($e['encounterDatetime'] ?? '')) : false, $results)));
        $ascending = $dates;
        sort($ascending);
        $order = count($dates) < 2 ? 'n/a' : ($dates === $ascending ? 'ascending' : ($dates === array_reverse($ascending) ? 'descending' : 'mixed'));

        $notes = [
            'result_count: '.count($results),
            'patient_filter: '.$this->patientFilter($results, 'patient', $patientUuid),
            'date_order (limit=5): '.$order,
            'has_next_link: '.(in_array('next', array_column(is_array($r['json']['links'] ?? null) ? $r['json']['links'] : [], 'rel'), true) ? 'yes' : 'no'),
            'fields: '.$this->fields($first),
        ];
        foreach (['encounterType', 'location', 'obs', 'orders', 'diagnoses', 'encounterProviders'] as $key) {
            $notes[] = "{$key}: ".(is_array($first) && array_key_exists($key, $first) ? $this->shape($first[$key]) : 'absent');
        }
        $obsValueTypes = [];
        foreach ($results as $encounter) {
            foreach (is_array($encounter['obs'] ?? null) ? $encounter['obs'] : [] as $ob) {
                $obsValueTypes[gettype($ob['value'] ?? null)] = true;
            }
        }
        $notes[] = 'obs_value_types: '.implode(', ', array_keys($obsValueTypes) ?: ['n/a']);

        $this->report('encounter', '/ws/rest/v1/encounter?patient=<uuid>&v=full&limit=5', $r, $notes);
    }

    private function probeObs(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/obs', ['patient' => $patientUuid, 'v' => 'full', 'limit' => 3]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];
        $first = $results[0] ?? null;

        $this->report('obs', '/ws/rest/v1/obs?patient=<uuid>&v=full&limit=3', $r, [
            'result_count: '.count($results),
            'patient_filter: '.$this->patientFilter($results, 'person', $patientUuid),
            'fields: '.$this->fields($first),
            'concept: '.(is_array($first) && isset($first['concept']) ? $this->shape($first['concept']) : 'absent'),
            'value_types: '.implode(', ', array_unique(array_map(fn ($o) => gettype($o['value'] ?? null), $results)) ?: ['n/a']),
        ]);
    }

    private function probeDiagnosis(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/diagnosis', ['patient' => $patientUuid, 'v' => 'full', 'limit' => 3]);
        $first = $r['json']['results'][0] ?? null;

        $this->report('diagnosis', '/ws/rest/v1/diagnosis?patient=<uuid>&v=full&limit=3', $r, [
            'result_count: '.count($r['json']['results'] ?? []),
            'fields: '.$this->fields($first),
        ]);
    }

    private function probeMedication(string $patientUuid): void
    {
        $r = $this->get('/ws/rest/v1/order', ['patient' => $patientUuid, 't' => 'drugorder', 'v' => 'full', 'limit' => 3]);
        $results = is_array($r['json']['results'] ?? null) ? $r['json']['results'] : [];

        $this->report('order (medication)', '/ws/rest/v1/order?patient=<uuid>&t=drugorder&v=full&limit=3', $r, [
            'result_count: '.count($results),
            'order_types_seen: '.implode(', ', array_unique(array_map(fn ($o) => (string) ($o['type'] ?? 'n/a'), $results)) ?: ['n/a']),
            'fields: '.$this->fields($results[0] ?? null),
        ]);
    }

    /**
     * Satu-satunya jalur HTTP command ini: GET, tanpa redirect, SSL verification default, timeout ketat.
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
        $this->line(sprintf('[%s] GET %s -> HTTP %s | %s', $resource, $path, $r['status'] ?? 'n/a', $r['error'] === null ? 'ACCESS OK' : 'FAILED ('.$r['error'].')'));

        foreach ($notes as $note) {
            $this->line('    '.$note);
        }
    }

    /**
     * Pastikan setiap hasil benar-benar milik pasien yang diminta (server bisa mengabaikan filter).
     *
     * @param  array<int, mixed>  $results
     */
    private function patientFilter(array $results, string $key, string $patientUuid): string
    {
        $foreign = array_filter($results, fn ($row) => is_array($row) && isset($row[$key]['uuid']) && $row[$key]['uuid'] !== $patientUuid);

        return $results === [] ? 'n/a (no results)' : ($foreign === [] ? 'honored' : 'IGNORED ('.count($foreign).' foreign rows)');
    }

    private function fields(mixed $value): string
    {
        return is_array($value) && $value !== [] ? implode(', ', array_keys($value)) : 'n/a';
    }

    /** Hanya bentuk struktur (nama field, jumlah), tidak pernah nilai. */
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
