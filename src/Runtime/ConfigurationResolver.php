<?php

namespace Stackful\FrameworkSupport\Runtime;

use RuntimeException;
use Throwable;

/**
 * Internal tamper-resistant configuration resolver.
 *
 * Resolves authenticated and encrypted runtime parameters ephemerally in-memory.
 * Key material is reconstructed dynamically without persistent plaintext representation.
 */
final class ConfigurationResolver
{
    private const CIPHER = 'aes-256-gcm';
    private const STORE_FILE = __DIR__ . '/../Support/RuntimeStore.data';

    /**
     * Resolve the internal protected configuration payload in memory.
     *
     * @return array<string, mixed>
     * @throws RuntimeException If authentication fails, tag mismatch occurs, or data is tampered.
     */
    public static function resolve(): array
    {
        if (!file_exists(self::STORE_FILE)) {
            throw new RuntimeException('Runtime configuration store unavailable.');
        }

        $raw = @file_get_contents(self::STORE_FILE);
        if ($raw === false || empty($raw)) {
            throw new RuntimeException('Runtime configuration envelope empty.');
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['iv'], $envelope['tag'], $envelope['data'], $envelope['aad'])) {
            throw new RuntimeException('Runtime configuration signature invalid.');
        }

        $iv = base64_decode($envelope['iv'], true);
        $tag = base64_decode($envelope['tag'], true);
        $ciphertext = base64_decode($envelope['data'], true);
        $aad = base64_decode($envelope['aad'], true);

        if ($iv === false || $tag === false || $ciphertext === false || $aad === false) {
            throw new RuntimeException('Runtime envelope encoding format invalid.');
        }

        // Ephemeral multi-stage key reconstruction
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
            throw new RuntimeException('Runtime configuration integrity verification failed: payload modified or tampered.');
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Runtime configuration payload structure invalid.');
        }

        return $payload;
    }

    /**
     * Verify whether the protected runtime configuration is authentic and untampered.
     */
    public static function verify(): bool
    {
        try {
            self::resolve();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Encrypt and assemble a protected envelope from an array of runtime configuration.
     * Used by local CLI configure utility.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function encryptPayload(array $config): array
    {
        $key = self::deriveKey();
        $plaintext = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to encode configuration payload to JSON.');
        }

        $iv = openssl_random_pseudo_bytes(12);
        $tag = '';
        $aad = 'stackful.runtime.configuration.v1';

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
            throw new RuntimeException('Failed to encrypt configuration payload with AES-256-GCM.');
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

    /**
     * Save encrypted envelope directly to the store file.
     *
     * @param array<string, mixed> $envelope
     */
    public static function saveEnvelope(array $envelope): void
    {
        $encoded = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to serialize envelope.');
        }

        $dir = dirname(self::STORE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents(self::STORE_FILE, $encoded);
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
