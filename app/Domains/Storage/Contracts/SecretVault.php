<?php

declare(strict_types=1);

namespace App\Domains\Storage\Contracts;

use App\Domains\Storage\Exceptions\SecretVaultException;
use App\Domains\Storage\Vault\SealedSecret;

/**
 * Envelope encryption for storage items and other secrets (spec 8.1, Part 23): every secret gets its own
 * data key (DEK); the DEK is wrapped by a master key (KMS in production). Fingerprints are keyed hashes
 * (HMAC-SHA256 with a pepper) used to detect duplicates without decrypting anything.
 */
interface SecretVault
{
    public function seal(string $plaintext): SealedSecret;

    /** @throws SecretVaultException when the data was tampered with or the key is wrong */
    public function open(SealedSecret $sealed): string;

    /** Hex HMAC-SHA256 of an already normalised value. */
    public function fingerprint(string $normalised): string;
}
