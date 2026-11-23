<?php

declare(strict_types=1);

namespace App\Domains\Violations\Enums;

/** Penalty of a violation rule (spec 5.17). Points thresholds escalate to store suspension and ban (17.2). */
enum Penalty: string
{
    case Warning = 'warning';
    case ListingSuspend = 'listing_suspend';
    case StoreSuspend = 'store_suspend';
    case Ban = 'ban';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = ucwords(str_replace('_', ' ', $case->value));
        }

        return $out;
    }
}
