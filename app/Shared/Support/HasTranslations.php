<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Translatable JSON columns ({"en": "...", "fr": "...", "ar": "..."}) with English fallback.
 */
trait HasTranslations
{
    public function translate(string $attribute, ?string $locale = null): string
    {
        return Translations::pick($this->getAttribute($attribute), $locale);
    }
}
