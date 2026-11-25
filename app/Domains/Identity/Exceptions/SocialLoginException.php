<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use DomainException;

/** Social login refused (ADR-033); the message is translated and safe to show. */
final class SocialLoginException extends DomainException
{
    public static function because(string $key): self
    {
        $text = __($key);

        return new self(is_string($text) ? $text : $key);
    }
}
