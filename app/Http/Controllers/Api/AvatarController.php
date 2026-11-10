<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AvatarController extends Controller
{
    /**
     * Menghasilkan avatar SVG inisial secara lokal tanpa ketergantungan pada layanan pihak ketiga.
     * Melindungi privasi nama pengguna dari pihak eksternal.
     */
    public function default(Request $request): Response
    {
        $name = trim((string) $request->query('name', 'User'));
        $initials = $this->extractInitials($name);
        $safeInitials = htmlspecialchars($initials, ENT_XML1, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
    <circle cx="64" cy="64" r="64" fill="#0D8ABC"/>
    <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="#FFFFFF" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="52" font-weight="600">{$safeInitials}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    private function extractInitials(string $name): string
    {
        if ($name === '') {
            return 'U';
        }

        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) >= 2) {
            $first = mb_substr($parts[0], 0, 1);
            $second = mb_substr(end($parts), 0, 1);

            return mb_strtoupper($first.$second);
        }

        return mb_strtoupper(mb_substr($name, 0, min(2, mb_strlen($name))));
    }
}
