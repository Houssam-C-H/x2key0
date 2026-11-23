<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Shared\Support\Translations;

/**
 * Serialises catalog models into the props the React pages expect.
 */
final class CatalogPresenter
{
    /**
     * Product card (spec 1.4.1): cover, region/platform badges, title, offers count, from-price, MSRP + discount.
     *
     * @return array<string, mixed>
     */
    public static function productCard(Product $product, string $locale): array
    {
        $price = $product->lowest_price_amount_cached;
        $msrp = $product->msrp_currency === 'USD' ? $product->msrp_amount : null;

        return [
            'id' => $product->public_id,
            'name' => $product->translate('name', $locale),
            'href' => $product->path($locale),
            'image' => $product->image_path,
            'platform' => $product->platform?->name,
            'region' => $product->region === null ? null : [
                'code' => $product->region->code,
                'name' => $product->region->translate('name', $locale),
                'flag' => $product->region->flag,
            ],
            'offers' => $product->offer_count_cached,
            'priceCents' => $price,
            'msrpCents' => $msrp,
            'discountPercent' => $price !== null && $msrp !== null && $msrp > $price
                ? (int) floor(($msrp - $price) * 100 / $msrp)
                : null,
        ];
    }

    /**
     * Attribute values of a product, labelled from the schema (product page spec 1.5).
     *
     * @return list<array{label: string, value: string}>
     */
    public static function attributeList(Product $product, string $locale): array
    {
        $schema = $product->gameCategory->attributeSchema();
        $values = $product->attribute_values ?? [];
        $rows = [];

        foreach ($schema->fields() as $field) {
            $value = $values[$field['key']] ?? null;
            if ($value === null) {
                continue;
            }

            $optionLabel = static function (mixed $v) use ($field, $locale): string {
                foreach ($field['options'] as $option) {
                    if ($option['value'] === $v) {
                        return Translations::pick($option['label'], $locale);
                    }
                }

                return is_scalar($v) ? (string) $v : '';
            };

            $display = match (true) {
                is_bool($value) => __($value ? 'catalog.yes' : 'catalog.no'),
                is_array($value) => implode(', ', array_map($optionLabel, $value)),
                default => $optionLabel($value).($field['unit'] !== null ? ' '.$field['unit'] : ''),
            };

            $rows[] = ['label' => Translations::pick($field['label'], $locale), 'value' => is_string($display) ? $display : ''];
        }

        return $rows;
    }

    /**
     * @return array{title: string, label: string, game: string, gameHref: string, typeCode: string, notice: string|null}
     */
    public static function gameCategory(GameCategory $gameCategory, string $locale): array
    {
        return [
            'title' => $gameCategory->title($locale),
            'label' => $gameCategory->label($locale),
            'game' => $gameCategory->game->translate('name', $locale),
            'gameHref' => "/{$locale}/{$gameCategory->game->slug}",
            'typeCode' => $gameCategory->type->code->value,
            'notice' => $gameCategory->noticeText($locale),
        ];
    }
}
