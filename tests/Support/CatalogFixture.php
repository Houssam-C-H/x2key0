<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Platform;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Region;
use Database\Factories\GameCategoryFactory;

/**
 * A small catalog whose facet counts can be worked out by hand:
 *
 *   P1  Alpha Standard   edition=standard  genres=[action]        mp=yes  year=2020  pc  EU  $10.00
 *   P2  Beta Deluxe      edition=deluxe    genres=[action, rpg]   mp=no   year=2022  ps  EU  $20.00
 *   P3  Gamma Standard   edition=standard  genres=[rpg]           mp=yes  year=2024  pc  US  (no price)
 *   P4  Delta Ultimate   edition=ultimate  genres=[shooter]       mp=yes  year=2019  ps  US  $5.00
 *   P5  Draft Standard   (draft — never listed)
 */
final class CatalogFixture
{
    /**
     * @return array{game: Game, gameCategory: GameCategory, products: array<string, Product>}
     */
    public static function build(): array
    {
        $pc = Platform::query()->create(['slug' => 'pc', 'name' => 'PC', 'sort' => 1]);
        $ps = Platform::query()->create(['slug' => 'ps', 'name' => 'PlayStation', 'sort' => 2]);
        $eu = Region::query()->create(['code' => 'EU', 'name' => ['en' => 'Europe'], 'flag' => '🇪🇺', 'sort' => 1]);
        $us = Region::query()->create(['code' => 'US', 'name' => ['en' => 'United States'], 'flag' => '🇺🇸', 'sort' => 2]);

        GameCategoryFactory::type(CategoryTypeCode::VideoGames);
        $game = Game::factory()->named('Star Quest', 'star-quest')->create();

        $gameCategory = GameCategory::factory()
            ->for($game)
            ->ofType(CategoryTypeCode::VideoGames)
            ->withSchema(self::schema())
            ->create(['notice' => ['en' => 'Independent sellers notice.']]);

        $make = static function (string $name, array $attrs, Platform $platform, Region $region, ?int $price, bool $draft = false) use ($gameCategory): Product {
            $factory = Product::factory()->for($gameCategory)->attrs($attrs);
            $factory = $draft ? $factory->draft() : $factory;
            $factory = $price !== null ? $factory->priced($price, 2) : $factory;

            return $factory->create([
                'name' => ['en' => $name],
                'slug' => str($name)->slug()->toString(),
                'platform_id' => $platform->id,
                'region_id' => $region->id,
            ]);
        };

        $products = [
            'p1' => $make('Alpha Standard', ['edition' => 'standard', 'genres' => ['action'], 'multiplayer' => true, 'release_year' => 2020], $pc, $eu, 1000),
            'p2' => $make('Beta Deluxe', ['edition' => 'deluxe', 'genres' => ['action', 'rpg'], 'multiplayer' => false, 'release_year' => 2022], $ps, $eu, 2000),
            'p3' => $make('Gamma Standard', ['edition' => 'standard', 'genres' => ['rpg'], 'multiplayer' => true, 'release_year' => 2024], $pc, $us, null),
            'p4' => $make('Delta Ultimate', ['edition' => 'ultimate', 'genres' => ['shooter'], 'multiplayer' => true, 'release_year' => 2019], $ps, $us, 500),
            'p5' => $make('Draft Standard', ['edition' => 'standard'], $pc, $eu, 100, draft: true),
        ];

        return ['game' => $game, 'gameCategory' => $gameCategory->fresh(['game', 'type']) ?? $gameCategory, 'products' => $products];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            ['key' => 'edition', 'type' => 'select', 'label' => ['en' => 'Edition', 'fr' => 'Édition'], 'options' => [
                ['value' => 'standard', 'label' => ['en' => 'Standard']],
                ['value' => 'deluxe', 'label' => ['en' => 'Deluxe']],
                ['value' => 'ultimate', 'label' => ['en' => 'Ultimate']],
            ]],
            ['key' => 'genres', 'type' => 'multiselect', 'label' => ['en' => 'Genre'], 'options' => [
                ['value' => 'action', 'label' => ['en' => 'Action']],
                ['value' => 'rpg', 'label' => ['en' => 'RPG']],
                ['value' => 'shooter', 'label' => ['en' => 'Shooter']],
            ]],
            ['key' => 'multiplayer', 'type' => 'boolean', 'label' => ['en' => 'Multiplayer']],
            ['key' => 'release_year', 'type' => 'number', 'label' => ['en' => 'Release year']],
            ['key' => 'notes', 'type' => 'text', 'label' => ['en' => 'Notes']],
        ];
    }
}
