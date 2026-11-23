<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum MessengerType: string
{
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';
    case Discord = 'discord';
    case Teams = 'teams';
    case QQ = 'qq';
}
