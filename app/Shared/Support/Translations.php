<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Picks a string from a translatable value ({"en": "...", "fr": "..."}): requested locale, then English,
 * then the first non-empty value. Plain strings are returned as-is.
 */
final class Translations
{
    public static function pick(mixed $values, ?string $locale = null): string
    {
        if (is_string($values)) {
            return $values;
        }

        if (! is_array($values)) {
            return '';
        }

        $locale ??= app()->getLocale();

        foreach ([$locale, 'en'] as $candidate) {
            if (isset($values[$candidate]) && is_string($values[$candidate]) && $values[$candidate] !== '') {
                return $values[$candidate];
            }
        }

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
