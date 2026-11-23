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
    case ReserveRelease = 'RESERVE_RELEASE';
    case Refund = 'REFUND';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
    // M11: seller money of a completed order under dispute / chargeback, and chargeback movements (spec 7.2, 9.4).
    case DisputeFreeze = 'FUNDS_FREEZE';
    case DisputeUnfreeze = 'FUNDS_UNFREEZE';
    case ChargebackOpened = 'CHARGEBACK';
    case ChargebackRecovered = 'CHARGEBACK_RECOVERY';
    case ChargebackReversed = 'CHARGEBACK_REVERSAL';
    // M13: withdrawals (spec 7.2 WITHDRAWAL_*, 6.6).
    case WithdrawalRequested = 'WITHDRAWAL_REQUESTED';
    case WithdrawalReleased = 'WITHDRAWAL_RELEASED';
    case WithdrawalPaid = 'WITHDRAWAL_PAID';
    case WithdrawalFailed = 'WITHDRAWAL_FAILED';
}
