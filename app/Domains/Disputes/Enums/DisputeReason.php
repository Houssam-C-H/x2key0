<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Enums;

/**
 * Why a dispute was opened (spec 5.10 / Part 10). `buyer_unresponsive` is the only seller reason.
 */
enum DisputeReason: string
{
    case NotReceived = 'not_received';
    case Partial = 'partial';
    case NotAsDescribed = 'not_as_described';
    case NotWorking = 'not_working';
    case AlreadyUsed = 'already_used';
    case WrongRegion = 'wrong_region';
    case AccountRecovered = 'account_recovered';
    case ServiceIncomplete = 'service_incomplete';
    case BuyerUnresponsive = 'buyer_unresponsive';
    case Other = 'other';

    /** Evidence is required to open (spec Part 10). */
    public function needsEvidence(): bool
    {
        return in_array($this, [self::NotWorking, self::AlreadyUsed, self::WrongRegion, self::AccountRecovered], true);
    }

    public function openableBy(DisputeRole $role): bool
    {
        return $role === DisputeRole::Seller ? $this === self::BuyerUnresponsive : $this !== self::BuyerUnresponsive;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
