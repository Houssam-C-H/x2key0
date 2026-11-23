<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
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

    public function priced(int $cents, int $offers = 1): static
    {
        return $this->afterCreating(function (Product $product) use ($cents, $offers): void {
            $product->forceFill(['lowest_price_amount_cached' => $cents, 'offer_count_cached' => $offers])->saveQuietly();
        });
    }
}
