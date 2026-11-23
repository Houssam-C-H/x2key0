<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Exceptions;

use DomainException;

/** A seller rule was broken; the message is translated and safe to show to the user. */
final class SellerException extends DomainException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(self::trans($key, $replace));
    }

    /**
     * @param  array<string, string>  $replace
     */
    public static function trans(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
