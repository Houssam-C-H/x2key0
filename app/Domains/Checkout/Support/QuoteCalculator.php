<?php

declare(strict_types=1);

namespace App\Domains\Checkout\Support;

use App\Domains\Offers\Models\Offer;
use App\Domains\Payments\Models\PaymentMethod;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;

/**
 * Prices a checkout (spec 1.6, 7.2, 9.1, Part 27). Integer money only, half-up rounding in Amount::bps.
 *
 * - Unit price follows the offer's quantity tiers.
 * - Buyer service charge = max(buyer_fee_bps × (subtotal − discounts), buyer_fee_min_amount), on the whole
 *   order whatever pays for it (spec 7.2 example).
 * - Category add-on fee = `policy_override.addon_fee_bps` × subtotal, paid by the buyer (spec 9.1).
 * - Credits pay first; the payment method surcharge (fee_bps + fee_fixed) applies to the PSP part only.
 * - Commission = seller level commission_bps × seller-side price; seller net = seller-side price − commission.
 * - Coupons (M14) and tax (VAT, founder decision) are zero for now.
 */
final class QuoteCalculator
{
    public function __construct(private readonly Settings $settings) {}

    public function quote(Offer $offer, int $quantity, ?PaymentMethod $method, int $creditsAvailable): Quote
    {
        $offer->loadMissing(['seller.level', 'gameCategory']);
        $currency = $offer->currency;

        $unit = $offer->unit_price_amount;
        foreach ($offer->quantity_tiers ?? [] as $tier) {
            if ($quantity >= $tier['min_qty']) {
                $unit = $tier['unit_price_amount'];
            }
        }

        $subtotal = Amount::ofMinor($unit, $currency)->multipliedBy($quantity);
        $discountPlatform = 0;
        $discountSeller = 0;
        $afterDiscounts = $subtotal->minus(Amount::ofMinor($discountPlatform + $discountSeller, $currency));

        $buyerFee = Amount::bpsWithMinimum($afterDiscounts, $this->settings->int('buyer_fee_bps'), Amount::ofMinor($this->settings->int('buyer_fee_min_amount'), $currency));

        $addonBps = $offer->gameCategory->policy_override['addon_fee_bps'] ?? 0;
        $addonFee = Amount::bps($subtotal, is_int($addonBps) && $addonBps > 0 ? $addonBps : 0);

        $tax = Amount::zero($currency);
        $beforeMethod = $afterDiscounts->plus($buyerFee)->plus($addonFee)->plus($tax);

        $credits = min(max(0, $creditsAvailable), Amount::toMinor($beforeMethod));
        $pspBase = Amount::toMinor($beforeMethod) - $credits;

        $methodFee = 0;
        if ($pspBase > 0 && $method !== null) {
            $methodFee = Amount::toMinor(Amount::bps(Amount::ofMinor($pspBase, $currency), $method->fee_bps)) + $method->fee_fixed;
        }

        $sellerSide = $subtotal->minus(Amount::ofMinor($discountSeller, $currency));
        $commissionBps = $offer->seller->level->commission_bps ?? $this->settings->int('commission_bps');
        $commission = Amount::bps($sellerSide, $commissionBps);

        return new Quote(
            currency: $currency,
            quantity: $quantity,
            unitPrice: $unit,
            subtotal: Amount::toMinor($subtotal),
            discountPlatform: $discountPlatform,
            discountSeller: $discountSeller,
            buyerFee: Amount::toMinor($buyerFee),
            addonFee: Amount::toMinor($addonFee),
            methodFee: $methodFee,
            tax: Amount::toMinor($tax),
            total: Amount::toMinor($beforeMethod) + $methodFee,
            credits: $credits,
            pspDue: $pspBase + $methodFee,
            commissionBps: $commissionBps,
            commission: Amount::toMinor($commission),
            sellerNet: Amount::toMinor($sellerSide->minus($commission)),
        );
    }
}
