<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Exceptions;

use DomainException;

final class PayoutException extends DomainException
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
