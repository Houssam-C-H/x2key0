<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/**
 * Seller profile status (spec 6.3). Transitions only through SellerProfileStateMachine.
 */
enum SellerStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Vacation = 'vacation';
    case Suspended = 'suspended';
    case Banned = 'banned';

    /** Can open the Seller Center (suspended sellers keep read access to orders and funds). */
    public function opensSellerCenter(): bool
    {
        return in_array($this, [self::Active, self::Vacation, self::Suspended], true);
    }

    /** Store page is public. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Active, self::Vacation], true);
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Inactive => [self::Active, self::Banned],
            self::Active => [self::Vacation, self::Suspended, self::Banned],
            self::Vacation => [self::Active, self::Suspended, self::Banned],
            self::Suspended => [self::Active, self::Banned],
            self::Banned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}
