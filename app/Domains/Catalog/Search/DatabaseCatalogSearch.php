<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;

/**
 * Reference implementation: loads the active products of one game×category and filters/counts in PHP.
 * Exact and database-agnostic; fine up to a few thousand products per game×category. Production
 * switches to Meilisearch, and a parity test checks both drivers return the same counts.
 */
final class DatabaseCatalogSearch implements CatalogSearch
{
    public function listing(ListingQuery $query): ListingResult
    {
        $products = Product::query()
            ->active()
            ->where('game_category_id', $query->gameCategoryId)
            ->with(['gameCategory.type', 'gameCategory.game', 'platform', 'region'])
            ->get();

        $docs = [];
        foreach ($products as $product) {
            $docs[$product->id] = ProductDocument::from($product);
        }

        $matching = array_filter($docs, fn (array $d): bool => $this->matches($d, $query, null));

        // Disjunctive counts: each facet is counted with every filter applied except its own.
        $facetKeys = [];
        foreach ($docs as $doc) {
            foreach (array_keys($doc['facets']) as $key) {
                $facetKeys[$key] = true;
            }
        }

        $facets = [];
        foreach (array_keys($facetKeys) as $key) {
            $counts = [];
            foreach ($docs as $doc) {
                if (! $this->matches($doc, $query, $key)) {
                    continue;
                }
                foreach ($doc['facets'][$key] ?? [] as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            ksort($counts);
            $facets[$key] = $counts;
        }

        $bounds = [];
        foreach ($docs as $doc) {
            foreach ($doc['numbers'] as $key => $number) {
                $bounds[$key] = [
                    'min' => min($bounds[$key]['min'] ?? $number, $number),
                    'max' => max($bounds[$key]['max'] ?? $number, $number),
                ];
            }
        }

        $sorted = array_values($matching);
        usort($sorted, fn (array $a, array $b): int => $this->compare($a, $b, $query->sort));

        $pageDocs = array_slice($sorted, ($query->page - 1) * $query->perPage, $query->perPage);
        $items = array_map(static fn (array $d): Product => $products->firstOrFail('id', $d['id']), $pageDocs);

        return new ListingResult(
            items: $items,
            total: count($sorted),
            facets: $facets,
            bounds: $bounds,
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function index(Product $product): void {}

    public function remove(Product $product): void {}

    /**
     * @param  array{names: list<string>, price: int|null, facets: array<string, list<string>>, numbers: array<string, float>}  $doc
     */
    private function matches(array $doc, ListingQuery $query, ?string $exceptFacet): bool
    {
        if ($query->text !== '') {
            $needle = mb_strtolower($query->text);
            $found = false;
            foreach ($doc['names'] as $name) {
                if (str_contains(mb_strtolower($name), $needle)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        foreach ($query->facets as $key => $selected) {
            if ($key === $exceptFacet) {
                continue;
            }
            if (array_intersect($doc['facets'][$key] ?? [], $selected) === []) {
                return false;
            }
        }

        foreach ($query->ranges as $key => $range) {
            $value = $doc['numbers'][$key] ?? null;
            if ($value === null
                || ($range['min'] !== null && $value < $range['min'])
                || ($range['max'] !== null && $value > $range['max'])) {
                return false;
            }
        }

        if ($query->priceMin !== null || $query->priceMax !== null) {
            $price = $doc['price'];
            if ($price === null
                || ($query->priceMin !== null && $price < $query->priceMin)
                || ($query->priceMax !== null && $price > $query->priceMax)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{id: int, names: list<string>, price: int|null, created_at: int}  $a
     * @param  array{id: int, names: list<string>, price: int|null, created_at: int}  $b
     */
    private function compare(array $a, array $b, string $sort): int
    {
        // Products without a price (no offers yet) always go last on price sorts.
        $byPrice = static fn (int $direction): int => match (true) {
            $a['price'] === null && $b['price'] === null => 0,
            $a['price'] === null => 1,
            $b['price'] === null => -1,
            default => $direction * ($a['price'] <=> $b['price']),
        };

        $result = match ($sort) {
            'price_asc' => $byPrice(1),
            'price_desc' => $byPrice(-1),
            'newest' => $b['created_at'] <=> $a['created_at'],
            'name' => strcasecmp($a['names'][0] ?? '', $b['names'][0] ?? ''),
            // Recommended: priced products first (cheapest), then newest. Offer ranking signals join in M5.
            default => $byPrice(1) ?: ($b['created_at'] <=> $a['created_at']),
        };

        return $result !== 0 ? $result : $a['id'] <=> $b['id'];
    }
}
