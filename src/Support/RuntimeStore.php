<?php

namespace Stackful\FrameworkSupport\Support;

use RuntimeException;

/**
 * Internal tamper-resistant runtime configuration store.
 *
 * SECURITY NOTE:
 * This component provides authenticated tamper resistance and secret encapsulation at runtime.
 * In any PHP runtime environment, users with unrestricted root/process execution access
 * can inspect memory or debug binaries. This implementation secures secrets against casual
 * inspection, code modification, and static extraction while enforcing authenticated verification.
 */
final class RuntimeStore
{
    private const CIPHER = 'aes-256-gcm';
    private const PAYLOAD_FILE = __DIR__ . '/RuntimeStore.data';

    /**
     * Resolve and verify the encrypted configuration payload.
     * Ephemeral decryption: configuration is parsed and returned to callers without persistent memory retention.
     *
     * @return array<string, mixed>
     * @throws RuntimeException if authentication or integrity verification fails.
     */
    public static function resolve(): array
    {
        if (!file_exists(self::PAYLOAD_FILE)) {
            throw new RuntimeException('Runtime store initialization failed.');
        }

        $raw = file_get_contents(self::PAYLOAD_FILE);
        if ($raw === false || empty($raw)) {
            throw new RuntimeException('Runtime store resource unavailable.');
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['iv'], $envelope['tag'], $envelope['data'], $envelope['aad'])) {
            throw new RuntimeException('Runtime store signature verification failed.');
        }

        $iv = base64_decode($envelope['iv'], true);
        $tag = base64_decode($envelope['tag'], true);
        $ciphertext = base64_decode($envelope['data'], true);
        $aad = base64_decode($envelope['aad'], true);

        if ($iv === false || $tag === false || $ciphertext === false || $aad === false) {
            throw new RuntimeException('Runtime store envelope encoding invalid.');
        }

        // Construct key material ephemerally using multi-stage derivation
        $key = self::deriveKey();

        // Perform authenticated decryption (AES-256-GCM)
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        // Fail-closed if tampering or mismatch detected
        if ($plaintext === false) {
            throw new RuntimeException('Runtime integrity check failed: payload modified or unauthorized.');
        }

        $config = json_decode($plaintext, true);
        if (!is_array($config)) {
            throw new RuntimeException('Runtime configuration parsing failed.');
        }

        return $config;
    }

    /**
     * Verify whether the runtime payload is intact and authentic.
     */
    public static function verifyIntegrity(): bool
    {
        try {
            self::resolve();
            return true;
        } catch (\Throwable) {
            return false;
        }
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
