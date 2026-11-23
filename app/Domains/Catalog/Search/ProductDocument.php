<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;

/**
 * The flat, driver-neutral view of a product used for filtering and facet counting.
 * Both drivers derive facet values from this, which is what keeps their counts identical.
 */
final class ProductDocument
{
    /**
     * @return array{id: int, game_category_id: int, active: bool, names: list<string>, platform: string|null, region: string|null, price: int|null, created_at: int, facets: array<string, list<string>>, numbers: array<string, float>}
     */
    public static function from(Product $product): array
    {
        $schema = $product->gameCategory->attributeSchema();
        $values = $product->attribute_values ?? [];
        $facets = [];
        $numbers = [];

        foreach ($schema->filterable() as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if ($field['type']->isFacet()) {
                $facets["attr.{$key}"] = match (true) {
                    is_bool($value) => [$value ? 'yes' : 'no'],
                    is_array($value) => array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)),
                    default => [is_scalar($value) ? (string) $value : ''],
                };
            } elseif (is_numeric($value)) {
                $numbers[$key] = (float) $value;
            }
        }

        if ($product->platform !== null) {
            $facets['platform'] = [$product->platform->slug];
        }

        if ($product->region !== null) {
            $facets['region'] = [$product->region->code];
        }

        $names = array_values(array_filter($product->name, 'is_string'));

        return [
            'id' => $product->id,
            'game_category_id' => $product->game_category_id,
            'active' => $product->status->value === 'active' && $product->deleted_at === null,
            'names' => $names,
            'platform' => $product->platform?->slug,
            'region' => $product->region?->code,
            'price' => $product->lowest_price_amount_cached,
            'created_at' => $product->created_at->getTimestamp(),
            'facets' => $facets,
            'numbers' => $numbers,
        ];
    }
}
