<?php

declare(strict_types=1);

namespace App\Domains\Offers\Exceptions;

use DomainException;

/** An offer rule was broken; the message is translated and safe to show to the user. */
final class OfferException extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = [], string $field = 'offer'): self
    {
        $text = __($key, $replace);
        $e = new self(is_string($text) ? $text : $key);
        $e->field = $field;

        return $e;
    }

    /** Form field the message belongs to. */
    public string $field = 'offer';
}
