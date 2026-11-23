<?php

declare(strict_types=1);

namespace App\Domains\Storage\Exceptions;

use RuntimeException;

/** Decryption failed. The message never contains key material or payload data. */
final class SecretVaultException extends RuntimeException
{
    public static function cannotOpen(): self
    {
        return new self('The secret could not be decrypted (wrong key or tampered data).');
    }
}
