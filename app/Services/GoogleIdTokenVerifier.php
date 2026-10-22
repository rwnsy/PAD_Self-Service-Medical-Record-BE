<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class GoogleIdTokenVerifier
{
    /**
     * @return array{sub: string, email: string, name: string}
     */
    public function verify(string $idToken): array
    {
        $audience = config('services.google.id_token_audience');

        if (! is_string($audience) || $audience === '') {
            throw new RuntimeException('Google ID token audience is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        } catch (ConnectionException $connectionException) {
            throw new RuntimeException('Google token verification is unavailable.', previous: $connectionException);
        }

        if (! $response->successful()) {
            throw new InvalidArgumentException('The Google ID token is invalid.');
        }

        $claims = $response->json();
        $issuer = $claims['iss'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (($claims['aud'] ?? null) !== $audience
            || ! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! is_numeric($expiresAt)
            || (int) $expiresAt <= now()->getTimestamp()
            || ! $emailVerified
            || ! is_string($claims['sub'] ?? null)
            || ! is_string($claims['email'] ?? null)
            || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The Google ID token claims are invalid.');
        }

        return [
            'sub' => $claims['sub'],
            'email' => $claims['email'],
            'name' => is_string($claims['name'] ?? null) ? $claims['name'] : 'Google User',
        ];
    }
}
