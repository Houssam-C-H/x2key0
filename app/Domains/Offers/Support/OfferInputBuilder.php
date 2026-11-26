<?php

declare(strict_types=1);

namespace App\Domains\Offers\Support;

use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;

/**
 * Builds SaveOffer input from flat fields — shared by the CSV import and the seller API, so both apply the form's
 * rules through SaveOffer. Values are strings ('' = not given).
 */
final class OfferInputBuilder
{
    /**
     * SaveOffer input from flat fields (CSV row or API body, values as strings); for updates, blank or missing fields keep the offer's current values.
     *
     * @param  array<string, string>  $c
     * @return array{game_category_id: int, product_id: int|null, service_type_id: int|null, title: string|null, description: array<string, string|null>, delivery_method: string, fulfillment: string, storage_group_id: int|null, attribute_values: array<string, mixed>, unit_price_amount: int, min_qty: int, max_qty: int|null, unit_label: string|null, integer_multiple: bool, quantity_tiers: list<array{min_qty: int, unit_price_amount: int}>, delivery_time_minutes: int, manual_stock_qty: int|null, insurance_days: int, acquisition_cost_amount: int|null, listing_days: int, publish_mode: string}
     */
    public static function from(array $c, ?Offer $o): array
    {
        $int = static fn (string $key, ?int $current): ?int => ($c[$key] ?? '') === '' ? $current : (ctype_digit($c[$key]) ? (int) $c[$key] : throw OfferException::because('offer.csv.not_number', ['column' => $key]));
        $price = ($c['unit_price'] ?? '') === '' ? $o?->unit_price_amount : self::cents($c['unit_price']);
        if ($price === null) {
            throw OfferException::because('offer.csv.not_number', ['column' => 'unit_price']);
        }

        return [
            'game_category_id' => $o->game_category_id ?? ($int('game_category_id', null) ?? throw OfferException::because('offer.csv.required', ['column' => 'game_category_id'])),
            'product_id' => $o !== null ? $o->product_id : $int('product_id', null),
            'service_type_id' => $int('service_type_id', $o?->service_type_id),
            'title' => ($c['title'] ?? '') === '' ? $o?->title : $c['title'],
            'description' => $o->description ?? [],
            'delivery_method' => $o !== null ? $o->delivery_method->value : (($c['delivery_method'] ?? '') !== '' ? $c['delivery_method'] : throw OfferException::because('offer.csv.required', ['column' => 'delivery_method'])),
            'fulfillment' => $o !== null ? $o->fulfillment->value : 'manual',
            'storage_group_id' => $o?->storage_group_id,
            'attribute_values' => $o->attribute_values ?? [],
            'unit_price_amount' => $price,
            'min_qty' => $int('min_qty', $o->min_qty ?? 1) ?? 1,
            'max_qty' => $int('max_qty', $o?->max_qty),
            'unit_label' => $o?->unit_label,
            'integer_multiple' => $o->integer_multiple ?? false,
            'quantity_tiers' => $o->quantity_tiers ?? [],
            'delivery_time_minutes' => $int('delivery_time_minutes', $o->delivery_time_minutes ?? 60) ?? 60,
            'manual_stock_qty' => $int('manual_stock_qty', $o?->manual_stock_qty),
            'insurance_days' => $o->insurance_days ?? 0,
            'acquisition_cost_amount' => $o?->acquisition_cost_amount,
            'listing_days' => $o->listing_days ?? OfferRules::LISTING_DAYS[2],
            'publish_mode' => $o !== null ? $o->publish_mode->value : 'immediate',
        ];
    }

    private static function cents(string $value): int
    {
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $value, $m) !== 1) {
            throw OfferException::because('offer.csv.not_number', ['column' => 'unit_price']);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
