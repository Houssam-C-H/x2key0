<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/** "Languages spoken" on the store page (spec 1.8 My Store). */
enum LanguageLevel: string
{
    case Native = 'native';
    case Fluent = 'fluent';
    case Basic = 'basic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }
}
