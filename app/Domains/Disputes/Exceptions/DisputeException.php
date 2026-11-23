<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Exceptions;

use DomainException;

/** A dispute rule was broken; the message is translated and safe to show. */
final class DisputeException extends DomainException
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
