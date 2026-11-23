<?php

declare(strict_types=1);

namespace App\Domains\Offers\Enums;

/**
 * Offer status (spec 6.2). Transitions only through OfferStateMachine; each writes an offer_status_changes row.
 *
 *   draft → pending_publish | pending_review | active
 *   pending_publish → pending_review | active          (seller publishes; review unless auto-approve)
 *   pending_review → active | rejected                 (moderator)
 *   rejected → pending_review | active                 (seller edits and resubmits)
 *   active ⇄ deactivated · active → expired → active (relist) · active ⇄ out_of_stock
 *   active | out_of_stock | deactivated | expired → pending_review (content edited by a seller without auto-approve)
 *   any → system_deactivated (moderator or violation, reason shown) → deactivated (staff restore)
 *
 * The `suspended` status of spec 6.2 maps to `system_deactivated` (OPEN_QUESTIONS #5).
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case PendingPublish = 'pending_publish';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Deactivated = 'deactivated';
    case OutOfStock = 'out_of_stock';
    case Expired = 'expired';
    case SystemDeactivated = 'system_deactivated';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingPublish, self::PendingReview, self::Active, self::OutOfStock, self::SystemDeactivated],
            self::PendingPublish => [self::PendingReview, self::Active, self::OutOfStock, self::Deactivated, self::SystemDeactivated],
            self::PendingReview => [self::Active, self::OutOfStock, self::Rejected, self::SystemDeactivated],
            self::Rejected => [self::PendingReview, self::Active, self::OutOfStock, self::SystemDeactivated],
            self::Active => [self::Deactivated, self::Expired, self::OutOfStock, self::PendingReview, self::SystemDeactivated],
            self::OutOfStock => [self::Active, self::Deactivated, self::Expired, self::PendingReview, self::SystemDeactivated],
            self::Deactivated => [self::Active, self::OutOfStock, self::PendingReview, self::SystemDeactivated],
            self::Expired => [self::Active, self::OutOfStock, self::PendingReview, self::Deactivated, self::SystemDeactivated],
            self::SystemDeactivated => [self::Deactivated],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Listed publicly and purchasable. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /** Counts against the seller level's `max_active_offers`. */
    public function countsTowardLimit(): bool
    {
        return in_array($this, [self::Active, self::OutOfStock, self::PendingReview], true);
    }

    /** Stock changes flip it between active and out_of_stock. */
    public function tracksStock(): bool
    {
        return in_array($this, [self::Active, self::OutOfStock], true);
    }

    /** The seller can deactivate it. */
    public function sellerCanDeactivate(): bool
    {
        return in_array($this, [self::Active, self::OutOfStock, self::Expired, self::PendingPublish], true);
    }

    /** The seller can (re)list it: deactivated, expired, pending publish or rejected after edits. */
    public function sellerCanRelist(): bool
    {
        return in_array($this, [self::Deactivated, self::Expired, self::PendingPublish, self::Rejected], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
