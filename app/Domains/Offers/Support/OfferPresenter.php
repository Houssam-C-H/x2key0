<?php

declare(strict_types=1);

namespace App\Domains\Offers\Support;

use App\Domains\Campaigns\Support\CampaignBadges;
use App\Domains\Catalog\Support\CatalogPresenter;
use App\Domains\Messaging\Support\Presence;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Models\Offer;
use App\Shared\Support\Translations;

/**
 * Serialises offers for the React pages: public rows/cards (spec 1.4.1 offer card, 1.5 offers table)
 * and the seller's Active Offers rows.
 */
final class OfferPresenter
{
    /** "Adequate stock" above this, "In stock" below (spec 1.5). */
    public const int ADEQUATE_STOCK = 10;

    /**
     * Relations a public row needs, for eager loading.
     *
     * @return list<string>
     */
    public static function publicRelations(): array
    {
        return ['seller.level', 'seller.stats', 'seller.user:id,last_seen_at', 'storageGroup', 'gameCategory.game', 'gameCategory.type', 'product'];
    }

    /**
     * Buy box / "Other sellers" row (spec 1.5).
     *
     * @param  array<int, true>  $favorited  offer ids
     * @return array<string, mixed>
     */
    public static function row(Offer $offer, string $locale, array $favorited = []): array
    {
        $stock = $offer->stock();

        return [
            'id' => $offer->public_id,
            'title' => $offer->title,
            'href' => $offer->path($locale),
            'seller' => self::seller($offer, $locale),
            'campaign' => app(CampaignBadges::class)->for($offer->id),
            'deliveryMethod' => $offer->delivery_method->value,
            'deliveryMinutes' => $offer->delivery_time_minutes,
            'auto' => $offer->fulfillment === Fulfillment::Auto,
            'stock' => $stock,
            'stockLabel' => $stock >= self::ADEQUATE_STOCK ? 'adequate' : ($stock > 0 ? 'in_stock' : 'out_of_stock'),
            'minQty' => $offer->min_qty,
            'maxQty' => $offer->max_qty,
            'integerMultiple' => $offer->integer_multiple,
            'unitLabel' => $offer->unit_label,
            'insuranceDays' => $offer->insurance_days,
            'priceCents' => $offer->unit_price_amount,
            'tiers' => array_map(static fn (array $t): array => ['minQty' => $t['min_qty'], 'priceCents' => $t['unit_price_amount']], $offer->quantity_tiers ?? []),
            'favorited' => isset($favorited[$offer->id]),
        ];
    }

    /**
     * Offer card for listings without catalog products, stores and history strips (spec 1.4.1 card style 2).
     *
     * @return array<string, mixed>
     */
    public static function card(Offer $offer, string $locale): array
    {
        return [
            'id' => $offer->public_id,
            'title' => $offer->title,
            'href' => $offer->path($locale),
            'gameCategory' => $offer->gameCategory->title($locale),
            'image' => $offer->product?->image_path,
            'seller' => self::seller($offer, $locale),
            'campaign' => app(CampaignBadges::class)->for($offer->id),
            'deliveryMinutes' => $offer->delivery_time_minutes,
            'insuranceDays' => $offer->insurance_days,
            'priceCents' => $offer->unit_price_amount,
            'unitLabel' => $offer->unit_label,
        ];
    }

    /**
     * Full public offer detail (single offer page, spec 1.5).
     *
     * @param  array<int, true>  $favorited
     * @return array<string, mixed>
     */
    public static function detail(Offer $offer, string $locale, array $favorited = []): array
    {
        $attributes = [];
        $schema = $offer->gameCategory->attributeSchema();

        foreach ($schema->fields() as $field) {
            $value = $offer->attribute_values[$field['key']] ?? null;
            if ($value === null) {
                continue;
            }

            $label = static function (mixed $v) use ($field, $locale): string {
                foreach ($field['options'] as $option) {
                    if ($option['value'] === $v) {
                        return Translations::pick($option['label'], $locale);
                    }
                }

                return is_scalar($v) ? (string) $v : '';
            };

            $display = match (true) {
                is_bool($value) => __($value ? 'catalog.yes' : 'catalog.no'),
                is_array($value) => implode(', ', array_map($label, $value)),
                default => $label($value).($field['unit'] !== null ? ' '.$field['unit'] : ''),
            };

            $attributes[] = ['label' => Translations::pick($field['label'], $locale), 'value' => is_string($display) ? $display : ''];
        }

        return [
            ...self::row($offer, $locale, $favorited),
            'description' => $offer->descriptionText($locale),
            'attributes' => $attributes,
            'recoveryDays' => $offer->account_recovery_protection_days,
            'gameCategory' => CatalogPresenter::gameCategory($offer->gameCategory, $locale),
            'gameCategoryHref' => $offer->gameCategory->path($locale),
            'product' => $offer->product === null ? null : ['name' => $offer->product->translate('name', $locale), 'href' => $offer->product->path($locale)],
        ];
    }

    /**
     * Active Offers table row (spec 1.8).
     *
     * @return array<string, mixed>
     */
    public static function sellerRow(Offer $offer, string $locale): array
    {
        return [
            'id' => $offer->id,
            'publicId' => $offer->public_id,
            'title' => $offer->title,
            'href' => $offer->path($locale),
            'service' => $offer->gameCategory->title($locale),
            'status' => $offer->status->value,
            'statusReason' => $offer->status_reason,
            'duplicateOf' => $offer->duplicate_of_offer_id,
            'deliveryMethod' => $offer->delivery_method->value,
            'deliveryMinutes' => $offer->delivery_time_minutes,
            'auto' => $offer->fulfillment === Fulfillment::Auto,
            'storageGroup' => $offer->storageGroup?->name,
            'stock' => $offer->stock(),
            'minQty' => $offer->min_qty,
            'priceCents' => $offer->unit_price_amount,
            'sort' => $offer->sort_weight,
            'expiresAt' => $offer->expires_at?->toIso8601String(),
            'createdAt' => $offer->created_at->toIso8601String(),
            'canDeactivate' => $offer->status->sellerCanDeactivate(),
            'canRelist' => $offer->status->sellerCanRelist(),
        ];
    }

    /**
     * @return array{name: string, href: string, stars: int, levelName: string|null, positiveRateBps: int|null, logoUrl: string|null}
     */
    private static function seller(Offer $offer, string $locale): array
    {
        $seller = $offer->seller;

        return [
            'name' => $seller->store_name,
            'href' => $seller->path($locale),
            'stars' => $seller->level->stars ?? 0,
            'levelName' => $seller->level?->translate('name', $locale),
            'positiveRateBps' => $seller->stats?->positive_rate_bps,
            'logoUrl' => $seller->logoUrl(),
            // Green dot (spec 12.1); only where the seller's user was loaded with the offer.
            'online' => $seller->relationLoaded('user') && app(Presence::class)->online($seller->user->last_seen_at),
        ];
    }
}
