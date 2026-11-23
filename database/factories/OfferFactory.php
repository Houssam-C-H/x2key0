<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\InsuranceMode;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Enums\PublishMode;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Models\StorageGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Active, manually delivered offer. Tests of the create flow itself use SaveOffer; this factory is for
 * setting the scene (product pages, listings, bulk operations).
 *
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_profile_id' => SellerProfile::factory(),
            'game_category_id' => GameCategory::factory(),
            'product_id' => null,
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => ['en' => fake()->sentence()],
            'delivery_method' => DeliveryMethod::DigitalKey,
            'fulfillment' => Fulfillment::Manual,
            'storage_group_id' => null,
            'attribute_values' => null,
            'attributes_hash' => hash('sha256', Str::random(32)),
            'unit_price_amount' => 1000,
            'currency' => 'USD',
            'min_qty' => 1,
            'max_qty' => null,
            'unit_label' => null,
            'integer_multiple' => false,
            'quantity_tiers' => null,
            'delivery_time_minutes' => 60,
            'manual_stock_qty' => 50,
            'sold_count' => 0,
            'status' => OfferStatus::Active,
            'account_recovery_protection_days' => null,
            'insurance_days' => 0,
            'insurance_mode' => InsuranceMode::None,
            'sort_weight' => 0,
            'acquisition_cost_amount' => null,
            'publish_mode' => PublishMode::Immediate,
            'listing_days' => 7,
            'expires_at' => now()->addDays(7),
            'published_at' => now(),
            'status_reason' => null,
            'duplicate_of_offer_id' => null,
            'version' => 1,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(['product_id' => $product->id, 'game_category_id' => $product->game_category_id, 'title' => $product->translate('name', 'en')]);
    }

    public function price(int $cents): static
    {
        return $this->state(['unit_price_amount' => $cents]);
    }

    public function status(OfferStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function fromStorage(StorageGroup $group): static
    {
        return $this->state([
            'seller_profile_id' => $group->seller_profile_id,
            'fulfillment' => Fulfillment::Auto,
            'storage_group_id' => $group->id,
            'manual_stock_qty' => null,
        ]);
    }
}
