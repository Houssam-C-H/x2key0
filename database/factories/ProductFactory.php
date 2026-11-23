<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Offers\Actions\RefreshProductOffers;
use App\Domains\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->word().' '.fake()->word().' '.fake()->word());

        return [
            'game_category_id' => GameCategory::factory(),
            'platform_id' => null,
            'region_id' => null,
            'slug' => Str::slug($name),
            'name' => ['en' => $name],
            'description' => ['en' => fake()->paragraph()],
            'image_path' => null,
            'attribute_values' => [],
            'status' => ProductStatus::Active,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function attrs(array $values): static
    {
        return $this->state(['attribute_values' => $values]);
    }

    /**
     * Gives the product live offers from different sellers: the first at $cents, the others a dollar apart,
     * then refreshes the product's cached lowest price and offer count from them.
     */
    public function priced(int $cents, int $offers = 1): static
    {
        return $this->afterCreating(function (Product $product) use ($cents, $offers): void {
            for ($i = 0; $i < $offers; $i++) {
                Offer::factory()->forProduct($product)->price($cents + $i * 100)->create();
            }

            app(RefreshProductOffers::class)->apply($product);
        });
    }
}
