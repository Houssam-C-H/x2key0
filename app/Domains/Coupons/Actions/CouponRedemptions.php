<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Actions;

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Coupons\Support\AppliedCoupons;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;

/**
 * Spending and giving back claims (spec 14.1, invariant 4.3 #8). Both run inside the order's transaction:
 *
 * - redeem: each claim is locked, re-checked (still available, not expired) and marked used by the order; one
 *   redemption row per claim (unique), the coupon's use counter goes up;
 * - restore: when the order is cancelled or fully refunded, every redemption is reversed once; claims that have
 *   not expired become available again (expired ones are marked expired).
 */
final class CouponRedemptions
{
    public function redeem(Order $order, AppliedCoupons $coupons): void
    {
        foreach ($coupons->claims() as $claim) {
            /** @var CouponClaim $locked */
            $locked = CouponClaim::query()->with('coupon')->lockForUpdate()->findOrFail($claim->id);
            if (! $locked->isUsable() || $locked->user_id !== $order->buyer_id) {
                throw CheckoutException::because('coupon.error.not_eligible', field: 'coupons');
            }

            $isStore = $locked->coupon->issuer === Coupon::SELLER;
            $locked->forceFill(['status' => CouponClaim::USED, 'used_order_id' => $order->id])->save();

            (new CouponRedemption)->forceFill([
                'order_id' => $order->id,
                'coupon_claim_id' => $locked->id,
                'amount' => $isStore ? $order->discount_seller_amount : $order->discount_platform_amount,
                'funded_by' => $isStore ? 'seller' : 'platform',
                'created_at' => now(),
            ])->save();

            Coupon::query()->whereKey($locked->coupon_id)->increment('used_count');
        }
    }

    public function restore(Order $order): void
    {
        $redemptions = CouponRedemption::query()->where('order_id', $order->id)->whereNull('reversed_at')->lockForUpdate()->get();

        foreach ($redemptions as $redemption) {
            $redemption->forceFill(['reversed_at' => now()])->save();

            /** @var CouponClaim|null $claim */
            $claim = CouponClaim::query()->lockForUpdate()->find($redemption->coupon_claim_id);
            if ($claim === null || $claim->status !== CouponClaim::USED) {
                continue;
            }

            $expired = $claim->expires_at !== null && $claim->expires_at->isPast();
            $claim->forceFill(['status' => $expired ? CouponClaim::EXPIRED : CouponClaim::AVAILABLE, 'used_order_id' => null])->save();
            Coupon::query()->whereKey($claim->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }
    }
}
