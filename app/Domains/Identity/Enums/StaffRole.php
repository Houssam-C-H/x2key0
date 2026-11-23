<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Staff roles (spec 3.2). Buyer/seller/affiliate are capabilities of a normal user,
 * not roles: selling is unlocked by an active seller profile (M4), affiliate by enrolment (M15).
 */
enum StaffRole: string
{
    case Support = 'support';
    case MerchantManager = 'merchant_manager';
    case Moderator = 'moderator';
    case Finance = 'finance';
    case Admin = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
