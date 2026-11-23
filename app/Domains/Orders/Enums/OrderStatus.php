<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/**
 * Order status (spec 6.1). Transitions only through OrderStateMachine; each writes an order_status_history row.
 * Dispute outcomes (M11) add paid|delivering → refunded and delivered → delivering (replacement).
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
            // → refunded: dispute decision refunds everything (M11).
            self::Paid => [self::Delivering, self::Delivered, self::Cancelled, self::Refunded],
            self::Delivering => [self::Delivered, self::Refunded],
            // → delivering: dispute replacement / redelivery sends delivered units back to the seller (M11).
            self::Delivered => [self::Completed, self::Refunded, self::Delivering],
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
