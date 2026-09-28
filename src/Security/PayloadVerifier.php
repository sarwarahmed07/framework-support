<?php

namespace Stackful\FrameworkSupport\Security;

use RuntimeException;

class PayloadVerifier
{
    /**
     * Validate decrypted payload fields, security constraints, domain match, and expiry.
     *
     * @param array<string, mixed> $data
     * @param string $expectedInstallationId
     * @param string $expectedDomain
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function verify(array $data, string $expectedInstallationId, string $expectedDomain): array
    {
        // 1. Mandatory fields
        if (empty($data['destination']) || empty($data['installation_id']) || empty($data['domain']) || empty($data['nonce'])) {
            throw new RuntimeException('Payload missing mandatory attributes.');
        }

        // 2. HTTPS validation
        $destination = (string) $data['destination'];
        if (!str_starts_with(strtolower($destination), 'https://')) {
            throw new RuntimeException('Destination must use secure HTTPS protocol.');
        }

        if (filter_var($destination, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Destination is not a valid URL.');
        }

        // 3. Installation ID check (timing-safe)
        if (!hash_equals($expectedInstallationId, (string) $data['installation_id'])) {
            throw new RuntimeException('Installation identifier mismatch.');
        }

        // 4. Domain match (case-insensitive normalized hostname comparison)
        $payloadHost = parse_url($data['domain'], PHP_URL_HOST) ?: $data['domain'];
        $expectedHost = parse_url($expectedDomain, PHP_URL_HOST) ?: $expectedDomain;
        if (strcasecmp((string) $payloadHost, (string) $expectedHost) !== 0) {
            throw new RuntimeException('Domain context mismatch.');
        }

        // 5. Expiry check
        $now = time();
        $issuedAt = isset($data['issued_at']) ? (int) $data['issued_at'] : 0;
        $expiresAt = isset($data['expires_at']) ? (int) $data['expires_at'] : 0;

        if ($expiresAt > 0 && $now > $expiresAt) {
            throw new RuntimeException('Payload has expired.');
        }

        if ($issuedAt > 0 && $issuedAt > ($now + 300)) {
            throw new RuntimeException('Payload issued in the future.');
        }

        return $data;
    }
}
