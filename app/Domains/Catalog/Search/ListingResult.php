<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;

final readonly class ListingResult
{
    /**
     * @param  list<Product>  $items  current page, relations loaded
     * @param  array<string, array<string, int>>  $facets  facet key => value => count (disjunctive)
     * @param  array<string, array{min: float, max: float}>  $bounds  number attribute => observed bounds
     */
    public function __construct(
        public array $items,
        public int $total,
        public array $facets,
        public array $bounds,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
