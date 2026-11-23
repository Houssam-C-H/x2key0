<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Enums;

enum DisputeRole: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Staff = 'staff';
    case System = 'system';

    public function other(): self
    {
        return match ($this) {
            self::Buyer => self::Seller,
            self::Seller => self::Buyer,
            default => $this,
        };
    }
}
