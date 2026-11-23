<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Enums\AttributeType;
use App\Domains\Catalog\Models\GameCategory;
use Illuminate\Http\Request;

/**
 * A listing request for one game×category (spec 1.4.1), parsed from the query string:
 *   ?q=skin&platform[]=pc&region[]=EU&f[account_type][]=skin&r[vbucks][min]=1000&price[min]=500&sort=price_asc&page=2
 * Unknown filter keys and option values are dropped, so the URL can never break the page.
 */
final readonly class ListingQuery
{
    public const array SORTS = ['recommended', 'price_asc', 'price_desc', 'newest', 'name'];

    public const int PER_PAGE = 24;

    /**
     * @param  array<string, list<string>>  $facets  facet key => selected values (platform, region, attribute keys)
     * @param  array<string, array{min: float|null, max: float|null}>  $ranges  number attribute => bounds
     */
    public function __construct(
        public int $gameCategoryId,
        public string $text = '',
        public array $facets = [],
        public array $ranges = [],
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public string $sort = 'recommended',
        public int $page = 1,
        public int $perPage = self::PER_PAGE,
    ) {}

    public static function fromRequest(Request $request, GameCategory $gameCategory): self
    {
        $schema = $gameCategory->attributeSchema();
        $facets = [];

        foreach (['platform', 'region'] as $key) {
            $values = self::stringList($request->query($key));
            if ($values !== []) {
                $facets[$key] = $values;
            }
        }

        $rawFacets = $request->query('f');
        $rawRanges = $request->query('r');
        $ranges = [];

        foreach ($schema->filterable() as $field) {
            $key = $field['key'];

            if ($field['type']->isFacet()) {
                $selected = self::stringList(is_array($rawFacets) ? ($rawFacets[$key] ?? null) : null);
                $allowed = match ($field['type']) {
                    AttributeType::Boolean => ['yes', 'no'],
                    default => array_column($field['options'], 'value'),
                };
                $selected = array_values(array_intersect($selected, $allowed));
                if ($selected !== []) {
                    $facets["attr.{$key}"] = $selected;
                }
            } elseif ($field['type'] === AttributeType::Number && is_array($rawRanges) && is_array($rawRanges[$key] ?? null)) {
                $min = self::number($rawRanges[$key]['min'] ?? null);
                $max = self::number($rawRanges[$key]['max'] ?? null);
                if ($min !== null || $max !== null) {
                    $ranges[$key] = ['min' => $min, 'max' => $max];
                }
            }
        }

        $price = $request->query('price');
        $sort = $request->query('sort');
        $page = $request->query('page');

        return new self(
            gameCategoryId: $gameCategory->id,
            text: is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 100) : '',
            facets: $facets,
            ranges: $ranges,
            priceMin: is_array($price) ? self::cents($price['min'] ?? null) : null,
            priceMax: is_array($price) ? self::cents($price['max'] ?? null) : null,
            sort: is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'recommended',
            page: is_numeric($page) ? max(1, min(500, (int) $page)) : 1,
        );
    }

    public function hasFilters(): bool
    {
        return $this->text !== '' || $this->facets !== [] || $this->ranges !== [] || $this->priceMin !== null || $this->priceMax !== null;
    }

    /**
     * Only platform/region filters are indexable (spec 22: noindex other facet combinations).
     */
    public function isIndexable(): bool
    {
        return $this->text === '' && $this->ranges === [] && $this->priceMin === null && $this->priceMax === null
            && array_diff(array_keys($this->facets), ['platform', 'region']) === []
            && array_sum(array_map('count', $this->facets)) <= 1
            && $this->page === 1;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $list = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);

        return array_values(array_unique(array_filter($list, static fn (mixed $v): bool => is_string($v) && $v !== '' && strlen($v) <= 64)));
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** Price inputs are in major units ("12.50") → integer cents. */
    private static function cents(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
