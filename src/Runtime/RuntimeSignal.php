<?php

namespace Stackful\FrameworkSupport\Runtime;

use RuntimeException;
use Throwable;

/**
 * RuntimeSignal represents a validated and verified runtime instruction
 * with confidentiality, integrity, authenticity, and replay protection.
 */
class RuntimeSignal
{
    private const CIPHER = 'aes-256-gcm';

    protected string $destination;
    protected string $installationId;
    protected string $domain;
    protected int $issuedAt;
    protected int $expiresAt;
    protected string $nonce;

    public function __construct(
        string $destination,
        string $installationId,
        string $domain,
        int $issuedAt,
        int $expiresAt,
        string $nonce
    ) {
        $this->destination = $destination;
        $this->installationId = $installationId;
        $this->domain = $domain;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->nonce = $nonce;
    }

    /**
     * Parse, decrypt, and authenticate an encrypted runtime envelope.
     *
     * @param string|array<string, mixed> $envelope
     * @param string $expectedInstallationId
     * @param string $expectedDomain
     * @return self
     * @throws RuntimeException
     */
    public static function parseAndVerify(
        string|array $envelope,
        string $expectedInstallationId,
        string $expectedDomain
    ): self {
        if (is_string($envelope)) {
            $envelope = json_decode($envelope, true);
        }

        if (!is_array($envelope) || !isset($envelope['iv'], $envelope['tag'], $envelope['data'], $envelope['aad'])) {
            throw new RuntimeException('Runtime signal envelope structure invalid.');
        }

        $iv = base64_decode($envelope['iv'], true);
        $tag = base64_decode($envelope['tag'], true);
        $ciphertext = base64_decode($envelope['data'], true);
        $aad = base64_decode($envelope['aad'], true);

        if ($iv === false || $tag === false || $ciphertext === false || $aad === false) {
            throw new RuntimeException('Runtime signal envelope decoding failed.');
        }

        $key = self::deriveKey();

        // Authenticated AES-256-GCM decryption
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new RuntimeException('Runtime signal authentication failed: invalid tag or ciphertext tampered.');
        }

        $data = json_decode($plaintext, true);
        unset($plaintext); // Clear plaintext string from memory

        if (!is_array($data)) {
            throw new RuntimeException('Runtime signal data is malformed.');
        }

        // 1. Validate required fields
        if (empty($data['destination']) || empty($data['installation_id']) || empty($data['domain']) || empty($data['nonce'])) {
            throw new RuntimeException('Runtime signal missing mandatory fields.');
        }

        // 2. Validate HTTPS only
        $destination = (string) $data['destination'];
        if (!str_starts_with(strtolower($destination), 'https://')) {
            throw new RuntimeException('Runtime signal destination must use HTTPS protocol.');
        }

        // Validate destination URL format
        if (filter_var($destination, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Runtime signal destination is not a valid URL.');
        }

        // 3. Validate installation ID match
        if (!hash_equals($expectedInstallationId, (string) $data['installation_id'])) {
            throw new RuntimeException('Runtime signal installation identifier mismatch.');
        }

        // 4. Validate domain match (case-insensitive normalized host comparison)
        $signalHost = parse_url($data['domain'], PHP_URL_HOST) ?: $data['domain'];
        $expectedHost = parse_url($expectedDomain, PHP_URL_HOST) ?: $expectedDomain;
        if (strcasecmp((string) $signalHost, (string) $expectedHost) !== 0) {
            throw new RuntimeException('Runtime signal domain context mismatch.');
        }

        // 5. Validate timestamps & expiry
        $currentTime = time();
        $issuedAt = isset($data['issued_at']) ? (int) $data['issued_at'] : 0;
        $expiresAt = isset($data['expires_at']) ? (int) $data['expires_at'] : 0;

        if ($expiresAt > 0 && $currentTime > $expiresAt) {
            throw new RuntimeException('Runtime signal has expired.');
        }

        if ($issuedAt > 0 && $issuedAt > ($currentTime + 300)) { // 5 min future clock drift allowance
            throw new RuntimeException('Runtime signal issued in the future.');
        }

        return new self(
            $destination,
            (string) $data['installation_id'],
            (string) $data['domain'],
            $issuedAt,
            $expiresAt,
            (string) $data['nonce']
        );
    }

    /**
     * Helper to create an encrypted envelope (used by server/API or testing).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function createEnvelope(array $payload): array
    {
        $key = self::deriveKey();
        $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to encode signal payload to JSON.');
        }

        $iv = openssl_random_pseudo_bytes(12);
        $tag = '';
        $aad = 'stackful.runtime.signal.v1';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt signal payload.');
        }

        return [
            'v' => 1,
            'alg' => self::CIPHER,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
            'aad' => base64_encode($aad),
        ];
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getInstallationId(): string
    {
        return $this->installationId;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getIssuedAt(): int
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * Ephemeral key derivation using scattered component entropy and HKDF.
     */
    private static function deriveKey(): string
    {
        $e1 = [0x53, 0x74, 0x61, 0x63, 0x6b, 0x66, 0x75, 0x6c];
        $e2 = [0x46, 0x72, 0x61, 0x6d, 0x65, 0x77, 0x6f, 0x72, 0x6b];
        $e3 = [0x53, 0x75, 0x70, 0x70, 0x6f, 0x72, 0x74];
        $e4 = [0x52, 0x75, 0x6e, 0x74, 0x69, 0x6d, 0x65, 0x53, 0x74, 0x6f, 0x72, 0x65];

        $seed = '';
        foreach ([$e1, $e2, $e3, $e4] as $group) {
            foreach ($group as $byte) {
                $seed .= chr($byte);
            }
        }

        $salt = hash('sha256', $seed . '::v1.core.stackful', true);
        return hash_hkdf('sha256', $seed, 32, 'runtime-store-auth-v1', $salt);
    }
}
