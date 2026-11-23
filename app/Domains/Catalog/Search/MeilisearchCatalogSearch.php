<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;

/**
 * Production listing search. One multi-search request per listing:
 *   [0] the page of results with every filter, [1] observed number bounds for the game×category,
 *   [2..] one query per active facet with that facet's own filter removed (disjunctive counts).
 *
 * Document fields: f_<facet> (lists, facet key dots → "__"), n_<attribute> (numbers), price, created_at, names.
 */
final class MeilisearchCatalogSearch implements CatalogSearch
{
    private bool $waitForTasks = false;

    public function __construct(
        private readonly Client $client,
        private readonly string $indexUid,
    ) {}

    /** Tests and sync commands wait for Meilisearch's async tasks to finish. */
    public function waitForTasks(bool $wait = true): self
    {
        $this->waitForTasks = $wait;

        return $this;
    }

    public function configure(): void
    {
        $task = $this->client->index($this->indexUid)->updateSettings([
            'searchableAttributes' => ['names'],
            'filterableAttributes' => [
                'game_category_id',
                'active',
                'price',
                ['attributePatterns' => ['f_*', 'n_*'], 'features' => ['facetSearch' => false, 'filter' => ['equality' => true, 'comparison' => true]]],
            ],
            'sortableAttributes' => ['price', 'created_at', 'name_sort', 'id'],
            'pagination' => ['maxTotalHits' => 100000],
            'faceting' => ['maxValuesPerFacet' => 500],
        ]);

        $this->wait($task);
    }

    public function listing(ListingQuery $query): ListingResult
    {
        /** @var list<non-empty-string> $base */
        $base = [
            'game_category_id = '.$query->gameCategoryId,
            'active = true',
        ];

        $queries = [];

        $queries[] = (new SearchQuery)
            ->setIndexUid($this->indexUid)
            ->setQuery($query->text)
            ->setFilter([...$base, ...$this->filters($query, null)])
            ->setSort($this->sort($query->sort))
            ->setPage($query->page)
            ->setHitsPerPage(max(1, $query->perPage))
            ->setAttributesToRetrieve(['id']);

        // Bounds and the list of facet fields come from the whole game×category.
        $queries[] = (new SearchQuery)
            ->setIndexUid($this->indexUid)
            ->setFilter($base)
            ->setFacets(['*'])
            ->setLimit(0);

        $activeFacets = array_keys($query->facets);
        foreach ($activeFacets as $facet) {
            $queries[] = (new SearchQuery)
                ->setIndexUid($this->indexUid)
                ->setQuery($query->text)
                ->setFilter([...$base, ...$this->filters($query, $facet)])
                ->setFacets([self::facetField($facet)])
                ->setLimit(0);
        }

        /** @var array{results: list<array<string, mixed>>} $response */
        $response = $this->client->multiSearch($queries);
        $results = $response['results'];

        $main = $results[0];
        $all = $results[1];

        /** @var array<string, array<string, int>> $allDistribution */
        $allDistribution = is_array($all['facetDistribution'] ?? null) ? $all['facetDistribution'] : [];
        /** @var array<string, array{min: float|int, max: float|int}> $stats */
        $stats = is_array($all['facetStats'] ?? null) ? $all['facetStats'] : [];

        // Facets without an own filter: counted with all filters applied (one extra query covering them all).
        $inactiveFields = array_values(array_filter(
            array_keys($allDistribution),
            fn (string $field): bool => str_starts_with($field, 'f_') && ! in_array(self::facetKey($field), $activeFacets, true),
        ));

        $facets = [];

        if ($inactiveFields !== []) {
            /** @var array{results: list<array<string, mixed>>} $extra */
            $extra = $this->client->multiSearch([
                (new SearchQuery)
                    ->setIndexUid($this->indexUid)
                    ->setQuery($query->text)
                    ->setFilter([...$base, ...$this->filters($query, null)])
                    ->setFacets($inactiveFields)
                    ->setLimit(0),
            ]);
            $facets = $this->distribution($extra['results'][0]['facetDistribution'] ?? []);
        }

        foreach ($activeFacets as $i => $facet) {
            $facets = [...$facets, ...$this->distribution($results[$i + 2]['facetDistribution'] ?? [])];
        }

        foreach (array_keys($allDistribution) as $field) {
            if (str_starts_with($field, 'f_')) {
                $facets[self::facetKey($field)] ??= [];
            }
        }
        ksort($facets);

        $bounds = [];
        foreach ($stats as $field => $stat) {
            if (str_starts_with($field, 'n_')) {
                $bounds[substr($field, 2)] = ['min' => (float) $stat['min'], 'max' => (float) $stat['max']];
            }
        }

        /** @var list<array{id: int}> $hits */
        $hits = is_array($main['hits'] ?? null) ? $main['hits'] : [];
        $ids = array_map(static fn (array $hit): int => (int) $hit['id'], $hits);

        $loaded = Product::query()
            ->whereIn('id', $ids)
            ->with(['gameCategory.type', 'gameCategory.game', 'platform', 'region'])
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            if ($loaded->has($id)) {
                $items[] = $loaded->get($id);
            }
        }

        /** @var list<Product> $items */
        return new ListingResult(
            items: $items,
            total: is_int($main['totalHits'] ?? null) ? $main['totalHits'] : count($items),
            facets: $facets,
            bounds: $bounds,
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function index(Product $product): void
    {
        $product->loadMissing(['gameCategory.type', 'platform', 'region']);
        $this->wait($this->client->index($this->indexUid)->addDocuments([self::document($product)], 'id'));
    }

    public function remove(Product $product): void
    {
        $this->wait($this->client->index($this->indexUid)->deleteDocument($product->id));
    }

    /**
     * Rebuilds the whole index from the database.
     *
     * @param  Builder<Product>|null  $scope
     */
    public function reindex(?Builder $scope = null): int
    {
        $this->configure();
        $this->wait($this->client->index($this->indexUid)->deleteAllDocuments());

        $count = 0;
        ($scope ?? Product::query())
            ->with(['gameCategory.type', 'platform', 'region'])
            ->chunkById(500, function ($products) use (&$count): void {
                $docs = [];
                foreach ($products as $product) {
                    $docs[] = self::document($product);
                }
                $this->wait($this->client->index($this->indexUid)->addDocuments($docs, 'id'));
                $count += count($docs);
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Product $product): array
    {
        $doc = ProductDocument::from($product);

        $out = [
            'id' => $doc['id'],
            'game_category_id' => $doc['game_category_id'],
            'active' => $doc['active'],
            'names' => $doc['names'],
            'name_sort' => mb_strtolower($doc['names'][0] ?? ''),
            'created_at' => $doc['created_at'],
        ];

        // Omitted (not null) so price sorts put unpriced products last, like the database driver.
        if ($doc['price'] !== null) {
            $out['price'] = $doc['price'];
        }

        foreach ($doc['facets'] as $key => $values) {
            $out[self::facetField($key)] = $values;
        }

        foreach ($doc['numbers'] as $key => $number) {
            $out['n_'.$key] = $number;
        }

        return $out;
    }

    /**
     * @return list<non-empty-string>
     */
    private function filters(ListingQuery $query, ?string $exceptFacet): array
    {
        $filters = [];

        foreach ($query->facets as $key => $values) {
            if ($key === $exceptFacet) {
                continue;
            }
            $quoted = array_map(static fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values);
            $filters[] = self::facetField($key).' IN ['.implode(', ', $quoted).']';
        }

        foreach ($query->ranges as $key => $range) {
            $field = 'n_'.$key;
            $filters[] = "{$field} EXISTS";
            if ($range['min'] !== null) {
                $filters[] = "{$field} >= {$range['min']}";
            }
            if ($range['max'] !== null) {
                $filters[] = "{$field} <= {$range['max']}";
            }
        }

        if ($query->priceMin !== null) {
            $filters[] = "price >= {$query->priceMin}";
        }
        if ($query->priceMax !== null) {
            $filters[] = "price <= {$query->priceMax}";
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    private function sort(string $sort): array
    {
        return match ($sort) {
            'price_asc' => ['price:asc', 'id:asc'],
            'price_desc' => ['price:desc', 'id:asc'],
            'newest' => ['created_at:desc', 'id:asc'],
            'name' => ['name_sort:asc', 'id:asc'],
            default => ['price:asc', 'created_at:desc', 'id:asc'],
        };
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function distribution(mixed $distribution): array
    {
        $out = [];

        if (! is_array($distribution)) {
            return $out;
        }

        foreach ($distribution as $field => $counts) {
            if (! is_string($field) || ! str_starts_with($field, 'f_') || ! is_array($counts)) {
                continue;
            }
            $values = [];
            foreach ($counts as $value => $count) {
                if (is_int($count) && $count > 0) {
                    $values[(string) $value] = $count;
                }
            }
            ksort($values);
            $out[self::facetKey($field)] = $values;
        }

        return $out;
    }

    /**
     * @return non-empty-string
     */
    private static function facetField(string $facetKey): string
    {
        return 'f_'.str_replace('.', '__', $facetKey);
    }

    private static function facetKey(string $field): string
    {
        return str_replace('__', '.', substr($field, 2));
    }

    private function wait(mixed $task): void
    {
        if ($this->waitForTasks && is_array($task) && is_int($task['taskUid'] ?? null)) {
            $this->client->waitForTask($task['taskUid'], 30000);
        }
    }
}
