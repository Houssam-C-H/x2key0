<?php

declare(strict_types=1);

namespace App\Domains\Storage\Exceptions;

use DomainException;

/** A storage rule was broken; the message is translated, safe to show, and never contains secrets. */
final class StorageException extends DomainException
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
