<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Support;

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;

/**
 * Which of a buyer's claims fit an offer, and what they take off (spec 14.1, 7.2):
 *
 * - eligible = claim available and not expired, coupon active in its window, scope matches the offer (games,
 *   category types, game×categories), minimum order on the list subtotal, buyer level ≥ the coupon's minimum;
 *   store coupons only in their own store;
 * - stacking: at most `coupon_max_stack_store` store coupon + `coupon_max_stack_platform` platform coupon
 *   (1 + 1), and a non-stackable coupon is used alone;
 * - store coupon (percent): per unit = unit × bps (half-up), the seller-side unit price never below 1 minor unit;
 * - platform coupon: fixed or percent (capped by `max_discount_amount`) of the seller-side subtotal, never more
 *   than it — the buyer still pays the fees.
 */
final class CouponPricing
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  list<string>  $claimPublicIds
     */
    public function apply(Offer $offer, int $quantity, int $unit, User $buyer, array $claimPublicIds): AppliedCoupons
    {
        $claimPublicIds = array_values(array_unique(array_filter($claimPublicIds)));
        if ($claimPublicIds === []) {
            return AppliedCoupons::none();
        }

        $claims = CouponClaim::query()->with('coupon')->where('user_id', $buyer->id)->whereIn('public_id', $claimPublicIds)->get();
        if ($claims->count() !== count($claimPublicIds)) {
            throw CheckoutException::because('coupon.error.unknown', field: 'coupons');
        }

        $store = null;
        $platform = null;
        foreach ($claims as $claim) {
            if (! $this->fits($claim, $offer, $quantity * $unit, $buyer)) {
                throw CheckoutException::because('coupon.error.not_eligible', field: 'coupons');
            }
            if ($claim->coupon->issuer === Coupon::SELLER) {
                if ($store !== null || $this->settings->int('coupon_max_stack_store') < 1) {
                    throw CheckoutException::because('coupon.error.stacking', field: 'coupons');
                }
                $store = $claim;
            } else {
                if ($platform !== null || $this->settings->int('coupon_max_stack_platform') < 1) {
                    throw CheckoutException::because('coupon.error.stacking', field: 'coupons');
                }
                $platform = $claim;
            }
        }

        if ($store !== null && $platform !== null && (! $store->coupon->stackable || ! $platform->coupon->stackable)) {
            throw CheckoutException::because('coupon.error.stacking', field: 'coupons');
        }

        $perUnit = $store === null ? 0 : min($unit - 1, Amount::toMinor(Amount::bps(Amount::ofMinor($unit, $offer->currency), $store->coupon->value)));
        $sellerSide = ($unit - max(0, $perUnit)) * $quantity;
        $platformDiscount = $platform === null ? 0 : $this->platformDiscount($platform->coupon, $sellerSide, $offer->currency);

        return new AppliedCoupons($store, $platform, max(0, $perUnit), $platformDiscount);
    }

    /**
     * The buyer's usable claims for this offer (checkout selector), best first per kind.
     *
     * @return list<array{id: string, issuer: string, label: string, name: string, discount: int}>
     */
    public function eligible(Offer $offer, int $quantity, int $unit, User $buyer, string $locale): array
    {
        $out = [];
        $claims = CouponClaim::query()->with('coupon')->where('user_id', $buyer->id)->where('status', CouponClaim::AVAILABLE)->get();

        foreach ($claims as $claim) {
            if (! $this->fits($claim, $offer, $quantity * $unit, $buyer)) {
                continue;
            }
            $coupon = $claim->coupon;
            $discount = $coupon->issuer === Coupon::SELLER
                ? min($unit - 1, Amount::toMinor(Amount::bps(Amount::ofMinor($unit, $offer->currency), $coupon->value))) * $quantity
                : $this->platformDiscount($coupon, $unit * $quantity, $offer->currency);

            $out[] = ['id' => $claim->public_id, 'issuer' => $coupon->issuer, 'label' => $coupon->label(), 'name' => $coupon->translate('name', $locale), 'discount' => max(0, $discount)];
        }

        usort($out, static fn (array $a, array $b): int => [$a['issuer'], -$a['discount']] <=> [$b['issuer'], -$b['discount']]);

        return $out;
    }

    public function fits(CouponClaim $claim, Offer $offer, int $listSubtotal, User $buyer): bool
    {
        $coupon = $claim->coupon;
        $offer->loadMissing('gameCategory.type');

        if (! $claim->isUsable() || ! $coupon->isLive() || $coupon->currency !== $offer->currency || $listSubtotal < $coupon->min_order_amount) {
            return false;
        }
        if ($coupon->issuer === Coupon::SELLER && $coupon->seller_profile_id !== $offer->seller_profile_id) {
            return false;
        }
        if ($coupon->user_level_min !== null && ($buyer->level->sort ?? 0) < $coupon->user_level_min) {
            return false;
        }

        $scope = $coupon->scope ?? [];
        if (($scope['game_ids'] ?? []) !== [] && ! in_array($offer->gameCategory->game_id, $scope['game_ids'], true)) {
            return false;
        }
        if (($scope['category_types'] ?? []) !== [] && ! in_array($offer->gameCategory->type->code->value, $scope['category_types'], true)) {
            return false;
        }

        return ($scope['game_category_ids'] ?? []) === [] || in_array($offer->game_category_id, $scope['game_category_ids'], true);
    }

    private function platformDiscount(Coupon $coupon, int $base, string $currency): int
    {
        $discount = $coupon->type === Coupon::FIXED
            ? $coupon->value
            : Amount::toMinor(Amount::bps(Amount::ofMinor($base, $currency), $coupon->value));

        if ($coupon->max_discount_amount !== null) {
            $discount = min($discount, $coupon->max_discount_amount);
        }

        return max(0, min($discount, $base));
    }
}
