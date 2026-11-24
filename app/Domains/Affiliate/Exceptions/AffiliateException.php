<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Exceptions;

use DomainException;

/** A refused affiliate action, with a translated message for the user. */
final class AffiliateException extends DomainException
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
