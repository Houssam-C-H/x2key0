<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use Database\Factories\GameCategoryFactory;

/**
 * A seller allowed to sell in the CatalogFixture game×category (video games, products P1–P4) and in a
 * unit category without products (Star Quest Accounts, schema: server select required, level number).
 */
final class OfferFixture
{
    /**
     * @return array{catalog: array<string, mixed>, accounts: GameCategory, profile: SellerProfile, seller: User}
     */
    public static function build(string $level = 'new'): array
    {
        $catalog = CatalogFixture::build();

        GameCategoryFactory::type(CategoryTypeCode::Accounts);
        $accounts = GameCategory::factory()
            ->for($catalog['game'])
            ->ofType(CategoryTypeCode::Accounts)
            ->withSchema([
                ['key' => 'server', 'type' => 'select', 'label' => ['en' => 'Server'], 'required' => true, 'options' => [
                    ['value' => 'eu', 'label' => ['en' => 'Europe']],
                    ['value' => 'na', 'label' => ['en' => 'North America']],
                ]],
                ['key' => 'level', 'type' => 'number', 'label' => ['en' => 'Level']],
            ])
            ->create();
        $accounts->load(['game', 'type']);

        $profile = SellerProfile::factory()->level($level)->create(['store_name' => 'Pixel Vault', 'slug' => 'pixel-vault']);

        foreach ([$catalog['gameCategory'], $accounts] as $gameCategory) {
            self::pass($profile, $gameCategory);
        }

        return ['catalog' => $catalog, 'accounts' => $accounts, 'profile' => $profile->refresh(), 'seller' => $profile->user];
    }

    public static function pass(SellerProfile $profile, GameCategory $gameCategory): void
    {
        $application = new SellerServiceApplication(['seller_profile_id' => $profile->id, 'game_category_id' => $gameCategory->id]);
        $application->status = ServiceApplicationStatus::Passed;
        $application->audited_at = now();
        $application->save();
    }

    /**
     * A valid Create New Offer form for product P1 (manual delivery, $12.50, 20 in stock).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $fx, array $overrides = []): array
    {
        return [
            'game_category_id' => $fx['catalog']['gameCategory']->id,
            'product_id' => $fx['catalog']['products']['p1']->id,
            'title' => 'Alpha Standard — instant Steam key',
            'description' => ['en' => 'Global key, delivered within minutes.', 'fr' => '', 'ar' => ''],
            'delivery_method' => 'digital_key',
            'fulfillment' => 'manual',
            'storage_group_id' => null,
            'attribute_values' => [],
            'unit_price' => '12.50',
            'min_qty' => 1,
            'max_qty' => null,
            'unit_label' => null,
            'integer_multiple' => false,
            'quantity_tiers' => [],
            'delivery_time_minutes' => 30,
            'manual_stock_qty' => 20,
            'insurance_days' => 0,
            'acquisition_cost' => null,
            'listing_days' => 7,
            'publish_mode' => 'immediate',
            'terms' => true,
            ...$overrides,
        ];
    }
}
