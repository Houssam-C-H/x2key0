<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Enums;

/**
 * Journal types (spec 7.2). Idempotency keys follow `{type}:{source_type}:{source_id}[:{seq}]`.
 * M7 posts the payment side; settlement, holds, refunds, affiliate and payouts arrive with their milestones.
 */
enum JournalType: string
{
    case CreditsSpent = 'CREDITS_SPENT';
    case CreditsRestored = 'CREDITS_RESTORED';
    case PaymentCaptured = 'PAYMENT_CAPTURED';
    case LateCapture = 'LATE_CAPTURE';
    case PromoCredit = 'PROMO_CREDIT';
    case PlatformCouponFunded = 'PLATFORM_COUPON_FUNDED';
    case OrderSettlement = 'ORDER_SETTLEMENT';
    case AffiliateAccrual = 'AFFILIATE_ACCRUAL';
    case HoldRelease = 'HOLD_RELEASE';
    case Refund = 'REFUND';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
}
