<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * Money inputs for admin forms: the column stores integer cents, staff type "12.50". Exact string maths, no floats.
 */
final class MinorUnits
{
    public static function input(string $field, string $currency = 'USD', bool $required = true): TextInput
    {
        return TextInput::make($field)
            ->prefix($currency)
            ->required($required)
            ->regex('/^\d+(\.\d{1,2})?$/')
            ->formatStateUsing(fn (mixed $state): ?string => is_int($state) ? self::toDecimal($state) : null)
            ->dehydrateStateUsing(fn (mixed $state): ?int => is_string($state) && $state !== '' ? self::fromDecimal($state) : null);
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $digits = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    public static function fromDecimal(string $value): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
