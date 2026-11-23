<?php

declare(strict_types=1);

namespace App\Domains\Certification\Enums;

/**
 * Certification Center levels (spec 1.9 / 5.1), in display order.
 */
enum CertificationType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Identity = 'identity';
    case Payment = 'payment';
    case Store = 'store';
    case Enterprise = 'enterprise';

    /** Types that must be verified before this one can start. */
    /**
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::Email => [],
            self::Phone => [self::Email],
            self::Identity => [self::Email, self::Phone],
            self::Payment, self::Store => [self::Identity],
            self::Enterprise => [self::Email],
        };
    }

    /** Milestone that delivers the verification flow (Email/Phone/Identity ship in M2). */
    public function milestone(): string
    {
        return match ($this) {
            self::Email, self::Phone, self::Identity => 'M2',
            self::Store => 'M4',
            self::Payment => 'M13',
            self::Enterprise => 'M18',
        };
    }
}
