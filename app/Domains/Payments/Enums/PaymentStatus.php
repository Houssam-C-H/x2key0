<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * Payment status (spec 5.7). Changed only by RecordPaymentResult, from verified provider events or the
 * sync job; `captured`, `failed` and `cancelled` are final.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::Failed, self::Cancelled], true);
    }

    public function canBecome(self $to): bool
    {
        if ($this->isFinal() || $to === $this) {
            return false;
        }

        return match ($to) {
            self::Created => false,
            self::Pending, self::RequiresAction => in_array($this, [self::Created, self::Pending, self::RequiresAction], true),
            default => true,
        };
    }
}
