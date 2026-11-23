<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/**
 * Order status (spec 6.1). Transitions only through OrderStateMachine; each writes an order_status_history row.
 * M6 implements the payment part: pending_payment → paid | payment_review | cancelled, payment_review → paid |
 * cancelled. Delivery, completion and refunds follow in M8–M11.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentReview = 'payment_review';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::PaymentReview, self::Cancelled],
            self::PaymentReview => [self::Paid, self::Cancelled],
            self::Paid => [self::Delivering, self::Delivered, self::Cancelled],
            self::Delivering => [self::Delivered],
            self::Delivered => [self::Completed, self::Refunded],
            self::Completed, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Buyer "Purchase Orders" tab (spec 6.1 tab mapping).
     */
    public function buyerTab(): string
    {
        return match ($this) {
            self::PendingPayment, self::PaymentReview => 'pending_payment',
            self::Paid, self::Delivering => 'pending_delivery',
            self::Delivered => 'to_confirm',
            self::Completed => 'completed',
            self::Cancelled, self::Refunded => 'cancelled',
        };
    }

    /**
     * @return list<self>
     */
    public static function forBuyerTab(string $tab): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->buyerTab() === $tab));
    }
}
