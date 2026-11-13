<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AdminSystemLogController extends Controller
{
    /**
     * Sensitive patterns to redact from log output.
     * These patterns prevent credential/token leakage.
     */
    private const REDACT_PATTERNS = [
        '/("password"\s*:\s*)"[^"]*"/i',
        '/("token"\s*:\s*)"[^"]*"/i',
        '/("credential"\s*:\s*)"[^"]*"/i',
        '/("Authorization"\s*:\s*)"[^"]*"/i',
        '/("nik"\s*:\s*)"[^"]*"/i',
        '/("phone"\s*:\s*)"[^"]*"/i',
        '/("google_sub"\s*:\s*)"[^"]*"/i',
        '/Bearer\s+[A-Za-z0-9|_\-\.]+/i',
    ];

    private const ALLOWED_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function index(Request $request): JsonResponse
    {
        $levelFilter = $request->query('level');
        $limit = min((int) $request->query('limit', 50), 200);

        if ($levelFilter && ! in_array(strtolower($levelFilter), self::ALLOWED_LEVELS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid log level filter.',
                'data' => null,
            ], 422);
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_SYSTEM_LOGS_VIEW', 'Admin viewed system logs.');

        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return response()->json([
                'success' => true,
                'message' => 'System log retrieved successfully.',
                'data' => [
                    'entries' => [],
                    'log_exists' => false,
                ],
            ]);
        }

        $lines = $this->readLastLines($logPath, $limit * 5); // read more to allow filtering
        $entries = $this->parseLogLines($lines, $levelFilter, $limit);

        return response()->json([
            'success' => true,
            'message' => 'System log retrieved successfully.',
            'data' => [
                'entries' => $entries,
                'log_exists' => true,
                'log_size_kb' => (int) (File::size($logPath) / 1024),
            ],
        ]);
    }

    /**
     * Read the last N lines from a file without loading it entirely.
     *
     * @return list<string>
     */
    private function readLastLines(string $path, int $n): array
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            return [];
        }

        $lines = [];
        $buffer = '';
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && count($lines) < $n) {
            $chunk = min(4096, $pos);
            $pos -= $chunk;
            fseek($handle, $pos);
            $buffer = fread($handle, $chunk).$buffer;
            $found = explode("\n", $buffer);
            $buffer = array_shift($found);
            $lines = array_merge(array_reverse($found), $lines);
        }

        if ($buffer !== '') {
            array_unshift($lines, $buffer);
        }

        fclose($handle);

        return array_slice(array_reverse($lines), 0, $n);
    }

    /**
     * Parse Laravel log lines into structured entries, applying redaction.
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function parseLogLines(array $lines, ?string $levelFilter, int $limit): array
    {
        // Laravel log format: [YYYY-MM-DD HH:MM:SS] production.LEVEL: message
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+\w+\.(\w+):\s+(.+)$/';
        $entries = [];

        foreach (array_reverse($lines) as $line) {
            if (count($entries) >= $limit) {
                break;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match($pattern, $line, $m)) {
                continue;
            }

            $level = strtolower($m[2]);
            $message = $this->redact($m[3]);

            if ($levelFilter && $level !== strtolower($levelFilter)) {
                continue;
            }

            $entries[] = [
                'timestamp' => $m[1],
                'level' => $level,
                'message' => mb_substr($message, 0, 500), // cap message length
            ];
        }

        return $entries;
    }

    /**
     * Redact sensitive patterns from a log line.
     */
    private function redact(string $text): string
    {
        foreach (self::REDACT_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '$1"[REDACTED]"', $text) ?? $text;
        }

        return $text;
    }
}
