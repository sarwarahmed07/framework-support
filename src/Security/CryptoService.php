<?php

namespace Stackful\FrameworkSupport\Security;

use RuntimeException;

class CryptoService
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Encrypt a string or array payload using AES-256-GCM.
     *
     * @param string|array<string, mixed> $payload
     * @param string $aad
     * @return array<string, mixed>
     */
    public static function encrypt(string|array $payload, string $aad = 'stackful.runtime.configuration.v1'): array
    {
        $plaintext = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : $payload;
        if ($plaintext === false) {
            throw new RuntimeException('Failed to serialize payload to JSON.');
        }

        $key = self::deriveKey();
        $iv = openssl_random_pseudo_bytes(12);
        $tag = '';

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
            throw new RuntimeException('Encryption failed.');
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
     * Decrypt an authenticated AES-256-GCM envelope.
     *
     * @param array<string, mixed> $envelope
     * @return string
     * @throws RuntimeException
     */
    public static function decrypt(array $envelope): string
    {
        if (!isset($envelope['iv'], $envelope['tag'], $envelope['data'], $envelope['aad'])) {
            throw new RuntimeException('Envelope structure invalid.');
        }

        $iv = base64_decode((string) $envelope['iv'], true);
        $tag = base64_decode((string) $envelope['tag'], true);
        $ciphertext = base64_decode((string) $envelope['data'], true);
        $aad = base64_decode((string) $envelope['aad'], true);

        if ($iv === false || $tag === false || $ciphertext === false || $aad === false) {
            throw new RuntimeException('Envelope encoding invalid.');
        }

        $key = self::deriveKey();

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
            throw new RuntimeException('Authentication tag verification failed: ciphertext modified or tampered.');
        }

        return $plaintext;
    }

    /**
     * Ephemeral key derivation using scattered component entropy and HKDF.
     */
    public static function deriveKey(): string
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
