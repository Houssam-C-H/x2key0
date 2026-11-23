<?php

declare(strict_types=1);

namespace App\Domains\Orders\Exceptions;

use DomainException;

/** A checkout or order rule was broken; the message is translated and safe to show. */
final class CheckoutException extends DomainException
{
    public string $field = 'checkout';

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = [], string $field = 'checkout'): self
    {
        $text = __($key, $replace);
        $e = new self(is_string($text) ? $text : $key);
        $e->field = $field;

        return $e;
    }
}
