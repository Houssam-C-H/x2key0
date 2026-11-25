<?php

declare(strict_types=1);

namespace App\Domains\Offers\Support;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Search\ListingQuery;
use App\Domains\Offers\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offer listing for game×categories without catalog products (currency, accounts, boosting…, spec 1.4.1): the same
 * filters as product listings — title search, attribute facets with disjunctive counts, number ranges, price,
 * sort — applied to the sellers' offer attributes. Filtering runs over the category's live offers in PHP (one
 * light query), like the database catalog search, so JSON attribute matching is identical on PostgreSQL and SQLite.
 */
final class OfferListing
{
    /** Upper bound of offers scanned per category; beyond it the oldest drop out of filtering. */
    private const int SCAN_LIMIT = 5000;

    /**
     * @param  int|null  $serviceTypeId  service type chip (ADR-032)
     * @param  list<string>  $with
     * @return array{page: LengthAwarePaginator<int, Offer>, facets: array<string, array<string, int>>, bounds: array<string, array{min: float, max: float}>}
     */
    public static function run(GameCategory $gameCategory, ListingQuery $query, int $perPage, ?int $serviceTypeId = null, array $with = []): array
    {
        $schema = $gameCategory->attributeSchema();

        $base = Offer::query()->live()->where('offers.game_category_id', $gameCategory->id)
            ->when($serviceTypeId !== null, fn (Builder $q) => $q->where('offers.service_type_id', $serviceTypeId));

        /** @var list<array{id: int, title: string, price: int, created: int, facets: array<string, list<string>>, numbers: array<string, float>}> $docs */
        $docs = [];
        foreach ((clone $base)->latest('offers.id')->limit(self::SCAN_LIMIT)->get(['offers.id', 'offers.title', 'offers.unit_price_amount', 'offers.attribute_values', 'offers.created_at']) as $offer) {
            $facets = [];
            $numbers = [];
            foreach ($schema->filterable() as $field) {
                $value = ($offer->attribute_values ?? [])[$field['key']] ?? null;
                if ($value === null) {
                    continue;
                }
                if ($field['type']->isFacet()) {
                    $facets['attr.'.$field['key']] = match (true) {
                        is_bool($value) => [$value ? 'yes' : 'no'],
                        is_array($value) => array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)),
                        default => [is_scalar($value) ? (string) $value : ''],
                    };
                } elseif (is_numeric($value)) {
                    $numbers[$field['key']] = (float) $value;
                }
            }
            $docs[] = ['id' => $offer->id, 'title' => $offer->title, 'price' => $offer->unit_price_amount, 'created' => $offer->created_at->getTimestamp(), 'facets' => $facets, 'numbers' => $numbers];
        }

        $facets = [];
        foreach ($schema->filterable() as $field) {
            if (! $field['type']->isFacet()) {
                continue;
            }
            $key = 'attr.'.$field['key'];
            $counts = [];
            foreach ($docs as $doc) {
                if (self::matches($doc, $query, $key)) {
                    foreach ($doc['facets'][$key] ?? [] as $v) {
                        $counts[$v] = ($counts[$v] ?? 0) + 1;
                    }
                }
            }
            $facets[$key] = $counts;
        }

        $bounds = [];
        foreach ($docs as $doc) {
            foreach ($doc['numbers'] as $key => $n) {
                $bounds[$key] = ['min' => min($bounds[$key]['min'] ?? $n, $n), 'max' => max($bounds[$key]['max'] ?? $n, $n)];
            }
        }

        $ids = array_values(array_map(static fn (array $d): int => $d['id'], array_filter($docs, static fn (array $d): bool => self::matches($d, $query, null))));

        $page = Offer::query()->whereIn('offers.id', $ids === [] ? [0] : $ids)->with($with);
        match ($query->sort) {
            'price_asc' => $page->orderBy('offers.unit_price_amount')->orderBy('offers.id'),
            'price_desc' => $page->orderByDesc('offers.unit_price_amount')->orderBy('offers.id'),
            'newest' => $page->orderByDesc('offers.id'),
            'name' => $page->orderBy('offers.title')->orderBy('offers.id'),
            default => $page->recommended(),
        };

        return [
            'page' => $page->paginate($perPage)->withQueryString(),
            'facets' => $facets,
            'bounds' => $bounds,
        ];
    }

    /**
     * @param  array{id: int, title: string, price: int, created: int, facets: array<string, list<string>>, numbers: array<string, float>}  $doc
     */
    private static function matches(array $doc, ListingQuery $query, ?string $exceptFacet): bool
    {
        if ($query->text !== '' && ! str_contains(mb_strtolower($doc['title']), mb_strtolower($query->text))) {
            return false;
        }
        if (($query->priceMin !== null && $doc['price'] < $query->priceMin) || ($query->priceMax !== null && $doc['price'] > $query->priceMax)) {
            return false;
        }
        foreach ($query->facets as $key => $selected) {
            if ($key !== $exceptFacet && array_intersect($doc['facets'][$key] ?? [], $selected) === []) {
                return false;
            }
        }
        foreach ($query->ranges as $key => $range) {
            $value = $doc['numbers'][$key] ?? null;
            if ($value === null || ($range['min'] !== null && $value < $range['min']) || ($range['max'] !== null && $value > $range['max'])) {
                return false;
            }
        }

        return true;
    }
}
