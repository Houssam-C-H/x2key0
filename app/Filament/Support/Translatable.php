<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Http\Middleware\ResolveLocale;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/**
 * One input per locale for a translatable JSON column ({"en": …, "fr": …, "ar": …}). English is required.
 */
final class Translatable
{
    public static function input(string $field, string $label, bool $required = true, bool $multiline = false, int $maxLength = 255): Fieldset
    {
        $inputs = [];

        foreach (ResolveLocale::contentLocales() as $locale) {
            $component = $multiline
                ? Textarea::make("{$field}.{$locale}")->rows(4)->maxLength(20000)
                : TextInput::make("{$field}.{$locale}")->maxLength($maxLength);

            $inputs[] = $component
                ->label(strtoupper($locale))
                ->required($required && $locale === 'en')
                ->extraInputAttributes($locale === 'ar' ? ['dir' => 'rtl'] : []);
        }

        return Fieldset::make($label)->schema($inputs)->columns($multiline ? 1 : 3);
    }
}
