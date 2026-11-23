<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller\Concerns;

use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\PublishMode;
use App\Domains\Offers\Support\OfferRules;
use App\Shared\Money\Amount;
use App\Shared\Validation\NoContactInfo;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shape validation of the offer form and conversion to SaveOffer's input. Prices arrive as decimal strings
 * ("12.50") and become integer cents through brick/money — never through floats.
 */
trait ReadsOfferForm
{
    private const string MONEY_PATTERN = '/^\d{1,7}(\.\d{1,2})?$/';

    /**
     * @return array<string, mixed>
     */
    private static function offerRules(bool $creating): array
    {
        $text = ['nullable', 'string', new NoContactInfo];

        return [
            'game_category_id' => ['required', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'title' => [...$text, 'max:160'],
            'description' => ['array'],
            'description.*' => [...$text, 'max:5000'],
            'delivery_method' => ['required', Rule::in(DeliveryMethod::values())],
            'fulfillment' => ['required', Rule::in(['auto', 'manual'])],
            'storage_group_id' => ['nullable', 'integer', 'required_if:fulfillment,auto'],
            'attribute_values' => ['array'],
            'unit_price' => ['required', 'string', 'regex:'.self::MONEY_PATTERN, 'not_in:0,0.0,0.00'],
            'min_qty' => ['required', 'integer', 'min:1', 'max:'.OfferRules::MAX_STOCK],
            'max_qty' => ['nullable', 'integer', 'min:1', 'max:'.OfferRules::MAX_STOCK],
            'unit_label' => [...$text, 'max:40'],
            'integer_multiple' => ['boolean'],
            'quantity_tiers' => ['array', 'max:'.OfferRules::MAX_TIERS],
            'quantity_tiers.*.min_qty' => ['required', 'integer', 'min:2', 'max:'.OfferRules::MAX_STOCK],
            'quantity_tiers.*.unit_price' => ['required', 'string', 'regex:'.self::MONEY_PATTERN],
            'delivery_time_minutes' => ['required', 'integer', Rule::in(OfferRules::DELIVERY_TIMES)],
            'manual_stock_qty' => ['nullable', 'integer', 'min:0', 'max:'.OfferRules::MAX_STOCK, 'required_if:fulfillment,manual'],
            'insurance_days' => ['required', 'integer', Rule::in(OfferRules::INSURANCE_DAYS)],
            'acquisition_cost' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
            'listing_days' => ['required', 'integer', Rule::in(OfferRules::LISTING_DAYS)],
            'publish_mode' => ['required', Rule::in(array_map(static fn (PublishMode $m): string => $m->value, PublishMode::cases()))],
            'terms' => $creating ? ['accepted'] : ['nullable'],
        ];
    }

    /**
     * @return array{
     *     game_category_id: int, product_id: int|null, title: string|null, description: array<string, string|null>,
     *     delivery_method: string, fulfillment: string, storage_group_id: int|null, attribute_values: array<string, mixed>,
     *     unit_price_amount: int, min_qty: int, max_qty: int|null, unit_label: string|null, integer_multiple: bool,
     *     quantity_tiers: list<array{min_qty: int, unit_price_amount: int}>, delivery_time_minutes: int,
     *     manual_stock_qty: int|null, insurance_days: int, acquisition_cost_amount: int|null, listing_days: int,
     *     publish_mode: string
     * }
     */
    private static function offerInput(Request $request): array
    {
        $description = [];
        $rawDescription = $request->input('description');
        foreach (is_array($rawDescription) ? $rawDescription : [] as $locale => $text) {
            if (is_string($locale) && in_array($locale, array_keys(config()->array('x2key.locales')), true)) {
                $description[$locale] = is_string($text) ? $text : null;
            }
        }

        $attributes = $request->input('attribute_values');
        /** @var array<string, mixed> $attributes */
        $attributes = is_array($attributes) ? array_filter($attributes, static fn (mixed $v, mixed $k): bool => is_string($k), ARRAY_FILTER_USE_BOTH) : [];

        $tiers = [];
        $rawTiers = $request->input('quantity_tiers');
        foreach (is_array($rawTiers) ? $rawTiers : [] as $tier) {
            if (is_array($tier) && is_numeric($tier['min_qty'] ?? null) && is_string($tier['unit_price'] ?? null)) {
                $tiers[] = ['min_qty' => (int) $tier['min_qty'], 'unit_price_amount' => self::cents($tier['unit_price'])];
            }
        }

        $acquisition = $request->input('acquisition_cost');

        return [
            'game_category_id' => $request->integer('game_category_id'),
            'product_id' => $request->filled('product_id') ? $request->integer('product_id') : null,
            'title' => is_string($t = $request->input('title')) ? $t : null,
            'description' => $description,
            'delivery_method' => $request->string('delivery_method')->toString(),
            'fulfillment' => $request->string('fulfillment')->toString(),
            'storage_group_id' => $request->filled('storage_group_id') ? $request->integer('storage_group_id') : null,
            'attribute_values' => $attributes,
            'unit_price_amount' => self::cents($request->string('unit_price')->toString()),
            'min_qty' => $request->integer('min_qty', 1),
            'max_qty' => $request->filled('max_qty') ? $request->integer('max_qty') : null,
            'unit_label' => is_string($u = $request->input('unit_label')) ? $u : null,
            'integer_multiple' => $request->boolean('integer_multiple'),
            'quantity_tiers' => $tiers,
            'delivery_time_minutes' => $request->integer('delivery_time_minutes'),
            'manual_stock_qty' => $request->filled('manual_stock_qty') ? $request->integer('manual_stock_qty') : null,
            'insurance_days' => $request->integer('insurance_days'),
            'acquisition_cost_amount' => is_string($acquisition) && $acquisition !== '' ? self::cents($acquisition) : null,
            'listing_days' => $request->integer('listing_days'),
            'publish_mode' => $request->string('publish_mode')->toString(),
        ];
    }

    /** "12.5" → 1250, exactly. */
    private static function cents(string $decimal): int
    {
        return Amount::toMinor(Money::of($decimal, 'USD'));
    }

    /** 1250 → "12.50" for form defaults. */
    private static function decimal(?int $cents): string
    {
        return $cents === null ? '' : (string) Money::ofMinor($cents, 'USD')->getAmount();
    }
}
