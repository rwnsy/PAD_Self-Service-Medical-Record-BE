<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Throttle window: only update DB once per 5 minutes to avoid write-per-request overhead.
     */
    private const THROTTLE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user === null) {
            return $response;
        }

        $lastSeen = $user->last_seen_at;
        $now = now('Asia/Jakarta');

        if ($lastSeen === null || $now->diffInSeconds($lastSeen) >= self::THROTTLE_SECONDS) {
            try {
                $user->forceFill(['last_seen_at' => $now])->saveQuietly();
            } catch (\Throwable) {
                // Never fail the request due to last_seen_at update
            }
        }

        return $response;
    }
}
