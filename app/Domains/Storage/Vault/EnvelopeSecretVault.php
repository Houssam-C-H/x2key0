<?php

declare(strict_types=1);

namespace App\Domains\Storage\Vault;

use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Exceptions\SecretVaultException;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * AES-256-GCM envelope encryption with a local master key (spec 8.1). Each seal draws a fresh 256-bit
 * data key and 96-bit IVs; the data key is wrapped by the master key with the key id as associated data,
 * so keys can be rotated by id. Production swaps the master-key wrap for a KMS call (ADR-014); the stored
 * format stays the same.
 */
final class EnvelopeSecretVault implements SecretVault
{
    private const string CIPHER = 'aes-256-gcm';

    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    public function __construct(
        #[SensitiveParameter] private readonly string $masterKey,
        #[SensitiveParameter] private readonly string $pepper,
        private readonly string $keyId = 'v1',
    ) {
        if (strlen($masterKey) !== 32 || strlen($pepper) < 32) {
            throw new InvalidArgumentException('The storage master key must be 32 bytes and the pepper at least 32 bytes.');
        }

        if (preg_match('/^[a-z0-9]{1,16}$/', $keyId) !== 1) {
            throw new InvalidArgumentException('Invalid storage key id.');
        }
    }

    /**
     * Keys come from config (base64) or, when empty (local dev), are derived from the app key with HKDF
     * so each purpose gets an independent key.
     */
    public static function fromConfig(#[SensitiveParameter] ?string $masterKey, #[SensitiveParameter] ?string $pepper, #[SensitiveParameter] string $appKey, string $keyId = 'v1'): self
    {
        $root = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        $decode = static function (?string $configured, string $info) use ($root): string {
            if ($configured !== null && $configured !== '') {
                $raw = base64_decode($configured, true);
                if ($raw === false) {
                    throw new InvalidArgumentException('Storage keys must be base64-encoded.');
                }

                return $raw;
            }

            if ($root === '') {
                throw new InvalidArgumentException('No application key: cannot derive storage keys.');
            }

            return hash_hkdf('sha256', $root, 32, $info);
        };

        return new self($decode($masterKey, 'x2key-storage-kek-v1'), $decode($pepper, 'x2key-storage-hmac-v1'), $keyId);
    }

    public function seal(#[SensitiveParameter] string $plaintext): SealedSecret
    {
        $dek = random_bytes(32);

        try {
            $ciphertext = self::encrypt($plaintext, $dek, '');
            $wrapped = self::encrypt($dek, $this->masterKey, $this->keyId);
        } finally {
            sodium_memzero($dek);
        }

        return new SealedSecret(base64_encode($ciphertext), $this->keyId.':'.base64_encode($wrapped));
    }

    public function open(SealedSecret $sealed): string
    {
        [$keyId, $wrapped] = array_pad(explode(':', $sealed->wrappedKey, 2), 2, '');

        if ($keyId !== $this->keyId) {
            throw SecretVaultException::cannotOpen();
        }

        $dek = self::decrypt((string) base64_decode($wrapped, true), $this->masterKey, $keyId);

        try {
            return self::decrypt((string) base64_decode($sealed->ciphertext, true), $dek, '');
        } finally {
            sodium_memzero($dek);
        }
    }

    public function fingerprint(#[SensitiveParameter] string $normalised): string
    {
        return hash_hmac('sha256', $normalised, $this->pepper);
    }

    private static function encrypt(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $key, string $aad): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $data = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);

        if ($data === false) {
            throw new SecretVaultException('Encryption failed.');
        }

        return $iv.$tag.$data;
    }

    private static function decrypt(string $blob, #[SensitiveParameter] string $key, string $aad): string
    {
        if (strlen($blob) < self::IV_BYTES + self::TAG_BYTES) {
            throw SecretVaultException::cannotOpen();
        }

        $iv = substr($blob, 0, self::IV_BYTES);
        $tag = substr($blob, self::IV_BYTES, self::TAG_BYTES);
        $data = substr($blob, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);

        if ($plaintext === false) {
            throw SecretVaultException::cannotOpen();
        }

        return $plaintext;
    }
}
