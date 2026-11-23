<?php

declare(strict_types=1);

namespace App\Domains\Storage\Vault;

/**
 * A sealed secret as stored: base64 ciphertext (iv ‖ tag ‖ data) and the wrapped data key
 * (`{keyId}:` + base64 iv ‖ tag ‖ wrapped DEK). Neither reveals anything without the master key.
 */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $wrappedKey,
    ) {}

    /** Never print the sealed values either: they are useless alone, but logs are not the place for them. */
    public function __debugInfo(): array
    {
        return ['ciphertext' => '[sealed]', 'wrappedKey' => '[sealed]'];
    }
}
