<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Actions;

use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Domains\Affiliate\Models\AffiliateReferral;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate commissions (spec Part 15, 7.2, ADR-027):
 *
 * - accrue — when a referred buyer's order completes inside the attribution window: base = the platform's net on the
 *   order (commission + buyer fees kept − platform coupon kept); level 1 = the inviter's rate, level 2 = the inviter's
 *   inviter's level-2 rate. AFFILIATE_ACCRUAL: EXP_AFFILIATE +, AFFILIATE_PENDING −. Once per affiliate and order.
 *   No commission when the affiliate is the buyer or the seller of the order;
 * - release — after the order's settlement hold + `affiliate_hold_extra_days`: AFFILIATE_RELEASE, pending → available;
 * - recalculate / reverseAll — a later refund lowers the base and the difference is reversed; a lost chargeback takes
 *   everything back (AFFILIATE_REVERSAL from pending, or from available, which may then go negative).
 */
final class AffiliateCommissions
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Settings $settings,
    ) {}

    /** The platform's net profit on the order (the commission base). */
    public static function base(Order $order): int
    {
        $fees = $order->fees() - $order->refunded_fee_amount;
        $promo = $order->discount_platform_amount - $order->refunded_discount_platform_amount;

        return max(0, $order->commission_amount + $fees - $promo);
    }

    public function accrue(Order $order): int
    {
        $referral = AffiliateReferral::query()->with('affiliate.parent')->where('referred_user_id', $order->buyer_id)->first();
        if ($referral === null || $order->status !== OrderStatus::Completed || ! $referral->affiliate->isActive()) {
            return 0;
        }
        $completedAt = $order->completed_at ?? now();
        if ($completedAt->greaterThan($referral->attribution_expires_at)) {
            return 0;
        }

        $sellerUser = SellerProfile::query()->whereKey($order->seller_profile_id)->value('user_id');
        $sellerUserId = is_numeric($sellerUser) ? (int) $sellerUser : 0;
        $excluded = [$order->buyer_id, $sellerUserId];

        $levels = [];
        $direct = $referral->affiliate;
        if (! in_array($direct->user_id, $excluded, true)) {
            $levels[1] = [$direct, $direct->directBps()];
        }
        $parent = $direct->parent;
        if ($parent !== null && $parent->isActive() && ! in_array($parent->user_id, $excluded, true)) {
            $levels[2] = [$parent, $parent->level2Bps()];
        }

        return DB::transaction(function () use ($order, $levels, $direct): int {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (AffiliateCommission::query()->where('order_id', $locked->id)->exists()) {
                return 0; // already accrued (idempotent)
            }

            $base = self::base($locked);
            $releaseAt = $this->releaseAt($locked);
            $posted = 0;

            foreach ($levels as $level => [$affiliate, $bps]) {
                $amount = $base === 0 ? 0 : Amount::toMinor(Amount::bps(Amount::ofMinor($base, $locked->currency), $bps));
                if ($amount <= 0) {
                    continue;
                }

                $commission = new AffiliateCommission;
                $commission->forceFill([
                    'affiliate_id' => $affiliate->id,
                    'order_id' => $locked->id,
                    'level' => $level,
                    'base_amount' => $base,
                    'rate_bps' => $bps,
                    'commission_amount' => $amount,
                    'reversed_amount' => 0,
                    'currency' => $locked->currency,
                    'status' => AffiliateCommission::PENDING,
                    'release_at' => $releaseAt,
                ])->save();

                $this->post->handle(new JournalDraft(
                    type: JournalType::AffiliateAccrual,
                    idempotencyKey: "AFFILIATE_ACCRUAL:commission:{$commission->id}",
                    lines: [
                        [AccountRef::platform(AccountCode::ExpAffiliate, $locked->currency), $amount],
                        [AccountRef::user(AccountCode::AffiliatePending, $affiliate->user_id, $locked->currency), -$amount],
                    ],
                    sourceType: 'affiliate_commission',
                    sourceId: $commission->id,
                    description: "Order {$locked->number}: affiliate level {$level}",
                ));
                $posted++;
            }

            if ($posted > 0) {
                $locked->forceFill(['affiliate_id' => $direct->id])->save();
            }

            return $posted;
        });
    }

    /** Releases held commissions whose time has come; idempotent. */
    public function release(): int
    {
        $released = 0;

        AffiliateCommission::query()->where('status', AffiliateCommission::PENDING)->where('release_at', '<=', now())->orderBy('id')
            ->chunkById(200, function ($commissions) use (&$released): void {
                foreach ($commissions as $commission) {
                    DB::transaction(function () use ($commission, &$released): void {
                        /** @var AffiliateCommission $locked */
                        $locked = AffiliateCommission::query()->with('affiliate')->lockForUpdate()->findOrFail($commission->id);
                        if ($locked->status !== AffiliateCommission::PENDING) {
                            return;
                        }
                        $net = $locked->net();
                        if ($net > 0) {
                            $this->post->handle(new JournalDraft(
                                type: JournalType::AffiliateRelease,
                                idempotencyKey: "AFFILIATE_RELEASE:commission:{$locked->id}",
                                lines: [
                                    [AccountRef::user(AccountCode::AffiliatePending, $locked->affiliate->user_id, $locked->currency), $net],
                                    [AccountRef::user(AccountCode::AffiliateAvailable, $locked->affiliate->user_id, $locked->currency), -$net],
                                ],
                                sourceType: 'affiliate_commission',
                                sourceId: $locked->id,
                                description: 'Affiliate commission released',
                            ));
                        }
                        $locked->forceFill(['status' => AffiliateCommission::APPROVED, 'released_at' => now()])->save();
                        $released++;
                    });
                }
            });

        return $released;
    }

    /** After a refund of a completed order: commission follows the lower base. Runs in the caller's transaction. */
    public function recalculate(Order $order, string $why = 'refund'): void
    {
        $base = self::base($order);

        foreach (AffiliateCommission::query()->with('affiliate')->where('order_id', $order->id)->lockForUpdate()->get() as $commission) {
            $target = $base === 0 ? 0 : Amount::toMinor(Amount::bps(Amount::ofMinor($base, $commission->currency), $commission->rate_bps));
            $excess = $commission->net() - min($target, $commission->commission_amount);
            if ($excess > 0) {
                $this->reverse($commission, $excess, $why);
            }
        }
    }

    /** Lost chargeback: every commission on the order is taken back. Runs in the caller's transaction. */
    public function reverseAll(Order $order, string $why = 'chargeback'): void
    {
        foreach (AffiliateCommission::query()->with('affiliate')->where('order_id', $order->id)->lockForUpdate()->get() as $commission) {
            if ($commission->net() > 0) {
                $this->reverse($commission, $commission->net(), $why);
            }
        }
    }

    private function reverse(AffiliateCommission $commission, int $amount, string $why): void
    {
        $from = $commission->status === AffiliateCommission::PENDING ? AccountCode::AffiliatePending : AccountCode::AffiliateAvailable;
        $after = $commission->reversed_amount + $amount;

        $this->post->handle(new JournalDraft(
            type: JournalType::AffiliateReversal,
            idempotencyKey: "AFFILIATE_REVERSAL:commission:{$commission->id}:{$after}",
            lines: [
                [AccountRef::user($from, $commission->affiliate->user_id, $commission->currency), $amount],
                [AccountRef::platform(AccountCode::ExpAffiliate, $commission->currency), -$amount],
            ],
            sourceType: 'affiliate_commission',
            sourceId: $commission->id,
            description: "Affiliate commission reversed ({$why})",
        ));

        $commission->forceFill([
            'reversed_amount' => $after,
            'status' => $after >= $commission->commission_amount ? AffiliateCommission::REVERSED : $commission->status,
        ])->save();
    }

    /** The order's settlement hold end (latest seller hold) + `affiliate_hold_extra_days` (spec Part 27). */
    private function releaseAt(Order $order): Carbon
    {
        $latest = Hold::query()->where('order_id', $order->id)->whereIn('kind', ['settlement', 'insurance'])->max('release_at');
        $start = is_string($latest) ? Carbon::parse($latest) : now();

        return $start->addDays($this->settings->int('affiliate_hold_extra_days'));
    }
}
