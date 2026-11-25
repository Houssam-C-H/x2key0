<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceType>
 */
final class ServiceTypeFactory extends Factory
{
    protected $model = ServiceType::class;

    public function definition(): array
    {
        $name = 'Service '.fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'category_type_id' => fn (): int => GameCategoryFactory::type(CategoryTypeCode::Boosting)->id,
            'code' => Str::slug($name, '_'),
            'name' => ['en' => $name],
            'description' => null,
            'sort' => 0,
            'is_enabled' => true,
        ];
    }

    public function ofType(CategoryTypeCode $code): self
    {
        return $this->state(['category_type_id' => GameCategoryFactory::type($code)->id]);
    }

    public function off(): self
    {
        return $this->state(['is_enabled' => false]);
    }
}
