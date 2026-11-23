<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameCategory>
 */
final class GameCategoryFactory extends Factory
{
    protected $model = GameCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'category_type_id' => fn (): int => self::type(CategoryTypeCode::VideoGames)->id,
            'slug' => 'video-games',
            'label_override' => null,
            'attribute_schema' => null,
            'buyer_input_schema' => null,
            'allowed_delivery_methods' => null,
            'policy_override' => null,
            'notice' => null,
            'is_enabled' => true,
        ];
    }

    public function ofType(CategoryTypeCode $code): static
    {
        return $this->state(fn (): array => [
            'category_type_id' => self::type($code)->id,
            'slug' => str_replace('_', '-', $code->value),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     */
    public function withSchema(array $schema): static
    {
        return $this->state(['attribute_schema' => $schema]);
    }

    public static function type(CategoryTypeCode $code): CategoryType
    {
        return CategoryType::query()->firstOrCreate(
            ['code' => $code],
            ['name' => ['en' => ucwords(str_replace('_', ' ', $code->value))], 'sort' => 0, 'is_enabled' => true],
        );
    }
}
