<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use DomainException;

/** A refused account deletion step, with a translated message. */
final class AccountDeletionException extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        $text = __($key, $replace);

        return new self(is_string($text) ? $text : $key);
    }
}
