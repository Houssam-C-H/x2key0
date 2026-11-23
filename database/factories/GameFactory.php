<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
final class GameFactory extends Factory
{
    protected $model = Game::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->word().' '.fake()->word());

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'name' => ['en' => $name],
            'publisher' => fake()->company(),
            'cover_path' => null,
            'icon_path' => null,
            'hero_path' => null,
            'is_hot' => false,
            'is_new' => false,
            'is_enabled' => true,
            'first_listed_at' => now(),
            'search_aliases' => [],
        ];
    }

    public function hot(): static
    {
        return $this->state(['is_hot' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    public function named(string $name, ?string $slug = null): static
    {
        return $this->state(['name' => ['en' => $name], 'slug' => $slug ?? Str::slug($name)]);
    }
}
