<?php

declare(strict_types=1);

namespace App\Domains\Offers\Support;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Exceptions\OfferException;

/**
 * Option lists and catalog-dependent rules of the offer form (spec 1.8 Create New Offers).
 */
final class OfferRules
{
    /** DELIVERY ETA choices in minutes: 15 min … 48 h, plus 72 h (max_delivery_time_minutes). */
    public const array DELIVERY_TIMES = [15, 30, 45, 60, 120, 180, 360, 720, 1440, 2880, 4320];

    /** Product expiration (listing duration) choices in days; 7 is the Part 27 default. */
    public const array LISTING_DAYS = [7, 15, 30];

    public const array INSURANCE_DAYS = [0, 3, 7, 15, 30, 60, 90];

    public const int MAX_TIERS = 5;

    public const int MAX_PRICE = 100_000_000; // $1,000,000.00

    public const int MAX_STOCK = 1_000_000;

    /**
     * @return list<DeliveryMethod>
     */
    public static function allowedMethods(GameCategory $gameCategory): array
    {
        $configured = array_values(array_filter(array_map(
            static fn (string $v): ?DeliveryMethod => DeliveryMethod::tryFrom($v),
            $gameCategory->allowed_delivery_methods ?? [],
        )));

        return $configured !== [] ? $configured : DeliveryMethod::defaultsFor($gameCategory->type->code);
    }

    public static function compulsoryInsuranceDays(GameCategory $gameCategory): int
    {
        $days = $gameCategory->policy_override['compulsory_insurance_days'] ?? 0;

        return is_int($days) && $days > 0 ? $days : 0;
    }

    /**
     * Quantity discount tiers ("Add Discount Settings"): each tier starts above the minimum quantity, tiers
     * are sorted by quantity, and each one is cheaper than the previous price.
     *
     * @param  list<array{min_qty: int, unit_price_amount: int}>  $tiers
     * @return list<array{min_qty: int, unit_price_amount: int}>
     */
    public static function tiers(array $tiers, int $unitPrice, int $minQty): array
    {
        if (count($tiers) > self::MAX_TIERS) {
            throw OfferException::because('offer.error.tiers', field: 'quantity_tiers');
        }

        usort($tiers, static fn (array $a, array $b): int => $a['min_qty'] <=> $b['min_qty']);

        $lastQty = $minQty;
        $lastPrice = $unitPrice;
        $out = [];

        foreach ($tiers as $tier) {
            if ($tier['min_qty'] <= $lastQty || $tier['unit_price_amount'] >= $lastPrice || $tier['unit_price_amount'] < 1) {
                throw OfferException::because('offer.error.tiers', field: 'quantity_tiers');
            }

            $out[] = ['min_qty' => $tier['min_qty'], 'unit_price_amount' => $tier['unit_price_amount']];
            $lastQty = $tier['min_qty'];
            $lastPrice = $tier['unit_price_amount'];
        }

        return $out;
    }
}
