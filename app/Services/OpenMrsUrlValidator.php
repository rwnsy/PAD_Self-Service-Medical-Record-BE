<?php

namespace App\Services;

use InvalidArgumentException;

class OpenMrsUrlValidator
{
    /**
     * Private, reserved, link-local, and loopback IPv4 CIDR blocks.
     */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',          // Current network
        '10.0.0.0/8',         // RFC 1918 Private
        '100.64.0.0/10',      // Shared Address Space / CGNAT
        '127.0.0.0/8',        // Loopback
        '169.254.0.0/16',     // Link-Local (includes 169.254.169.254 metadata)
        '172.16.0.0/12',      // RFC 1918 Private
        '192.0.0.0/24',       // IETF Protocol Assignments
        '192.0.2.0/24',       // Documentation TEST-NET-1
        '192.88.99.0/24',     // 6to4 Relay Anycast
        '192.168.0.0/16',     // RFC 1918 Private
        '198.18.0.0/15',      // Benchmarking
        '198.51.100.0/24',    // Documentation TEST-NET-2
        '203.0.113.0/24',     // Documentation TEST-NET-3
        '224.0.0.0/4',        // Multicast
        '240.0.0.0/4',        // Reserved
        '255.255.255.255/32', // Broadcast
    ];

    /**
     * Blocked hostnames associated with internal / loopback / cloud metadata.
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'metadata',
        'instance-data',
    ];

    /**
     * Validate an OpenMRS endpoint URL against SSRF and policy requirements.
     *
     * @param  string  $url  The endpoint URL
     * @param  bool|null  $strict  Override strict checking (when null, defaults based on environment/config)
     *
     * @throws InvalidArgumentException
     */
    public function validate(string $url, ?bool $strict = null): void
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Endpoint URL must be a valid, absolute URL with scheme and host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS protocols are supported.');
        }

        $isProduction = app()->isProduction();
        $enforceHttps = (bool) config('services.openmrs.enforce_https', $isProduction);
        $allowLocal = config('services.openmrs.allow_local_network');

        if ($allowLocal === null) {
            $allowLocal = ! $isProduction;
        }

        $isStrict = $strict ?? (! $allowLocal || $isProduction || (bool) config('services.openmrs.strict_ssrf', false));

        // 1. Enforce HTTPS in production or when configured
        if ($isStrict || $enforceHttps) {
            if ($scheme !== 'https') {
                throw new InvalidArgumentException('Production environment requires HTTPS for OpenMRS endpoint.');
            }
        }

        // 2. Check allowed hosts if allowlist is configured
        /** @var list<string> $allowedHosts */
        $allowedHosts = config('services.openmrs.allowed_hosts', []);
        if (! empty($allowedHosts) && is_array($allowedHosts)) {
            $matched = false;
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(trim((string) $allowed));
                if ($allowed === '') {
                    continue;
                }
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                throw new InvalidArgumentException("Host '{$host}' is not in the allowed hosts list.");
            }
        }

        // 3. In non-strict mode (local dev/testing), allow localhost/private addresses
        if (! $isStrict) {
            return;
        }

        // 4. Strict mode: check blocked hostnames
        if ($this->isBlockedHostname($host)) {
            throw new InvalidArgumentException('Access to localhost or internal metadata hostname is forbidden.');
        }

        // 5. Strict mode: IP or DNS resolution check
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new InvalidArgumentException('Access to private, loopback, or metadata IP addresses is forbidden.');
            }
        } else {
            // Host is a domain name — resolve IPs to detect private/loopback/cloud metadata
            $resolvedIps = @gethostbynamel($host);

            if ($resolvedIps === false || empty($resolvedIps)) {
                throw new InvalidArgumentException("Could not resolve host: {$host}");
            }

            foreach ($resolvedIps as $ip) {
                if (! $this->isPublicIp($ip)) {
                    throw new InvalidArgumentException("Host resolves to a disallowed private or loopback IP address ({$ip}).");
                }
            }
        }
    }

    /**
     * Check if a hostname matches known internal or loopback patterns.
     */
    private function isBlockedHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return true;
        }

        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        return false;
    }

    /**
     * Determine if an IP address is a publicly-routable, non-private, non-loopback IP.
     */
    public function isPublicIp(string $ip): bool
    {
        // 1. PHP filter validate flags
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        // 2. Extra explicit check for IPv4 CIDR blocks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    return false;
                }
            }
        }

        // 3. Extra explicit check for IPv6 loopback / unique local
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an IPv4 address falls within a given CIDR block.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskInt = (int) $mask;
        if ($maskInt === 0) {
            return true;
        }

        $maskBits = -1 << (32 - $maskInt);

        return ($ipLong & $maskBits) === ($subnetLong & $maskBits);
    }
}
