<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Enums;

/**
 * Withdrawal status (spec 6.6): requested → approved → processing → paid; requested → rejected | cancelled;
 * processing → failed (money returned). Only WithdrawalStateMachine changes it.
 */
enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Processing],
            self::Processing => [self::Paid, self::Failed],
            self::Paid, self::Rejected, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Processing], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Approved->value, self::Processing->value];
    }
}
