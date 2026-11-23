<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Deleting = 'deleting';
    case Deleted = 'deleted';

    public function canLogIn(): bool
    {
        return $this === self::Active;
    }
}
