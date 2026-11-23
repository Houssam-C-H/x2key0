<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

enum ContactChannel: string
{
    case Chat = 'chat';
    case Email = 'email';
    case Telegram = 'telegram';
    case Whatsapp = 'whatsapp';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(static fn (self $c): string => $c->value, self::cases()),
            array_map(static fn (self $c): string => ucfirst($c->value), self::cases()),
        );
    }
}
