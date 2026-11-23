<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Support;

use App\Domains\Coupons\Models\CouponClaim;

/**
 * Coupons applied to one checkout: the store coupon lowers every unit's seller-side price by `sellerUnitDiscount`;
 * the platform coupon takes `platformDiscount` off the order, funded by the platform.
 */
final readonly class AppliedCoupons
{
    public function __construct(
        public ?CouponClaim $store = null,
        public ?CouponClaim $platform = null,
        public int $sellerUnitDiscount = 0,
        public int $platformDiscount = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @return list<CouponClaim>
     */
    public function claims(): array
    {
        return array_values(array_filter([$this->store, $this->platform]));
    }
}
