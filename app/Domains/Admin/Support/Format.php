<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Shared\Money\Amount;
use BackedEnum;
use DateTimeInterface;

/** Small display helpers shared by the admin 360° pages. */
final class Format
{
    public static function money(int $minor, string $currency = 'USD'): string
    {
        return $currency.' '.Amount::ofMinor($minor, $currency)->getAmount();
    }

    public static function date(?DateTimeInterface $at): string
    {
        return $at === null ? '—' : $at->format('Y-m-d H:i');
    }

    public static function enum(mixed $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        return is_scalar($raw) ? str_replace('_', ' ', (string) $raw) : '—';
    }
}
