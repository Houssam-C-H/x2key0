<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use DomainException;

final class ChargebackException extends DomainException
{
    public static function because(string $key): self
    {
        $text = __($key);

        return new self(is_string($text) ? $text : $key);
    }
}
