<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Actions\RecountCatalog;
use App\Domains\Catalog\Enums\AttributeType;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Identity\Actions\RegisterUser;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Actions\SaveOffer;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Support\OfferRules;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Sellers\Models\SellerStoreMedia;
use App\Domains\Storage\Actions\ImportStorageItems;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Support\StoragePayload;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local demo offers (spec 24: "sellers with levels, offers of every delivery method, storage stock") so the
 * product pages, listings, stores, Active Offers and Storage render with data. Rows are bulk-inserted
 * (demo data, no history rows); seller_demo also gets storage groups — one deliberately below its
 * threshold so the stock alert bar shows. Store names and texts are original.
 */
final class OfferSeeder extends Seeder
{
    private const array STORES = [
        ['nova_keys', 'Nova Keys', 'gold'],
        ['arcade_depot', 'Arcade Depot', 'silver'],
        ['quest_supply', 'Quest Supply', 'bronze'],
        ['level_up_mart', 'Level Up Mart', 'new'],
    ];

    public function run(): void
    {
        if (DB::table('offers')->exists()) {
            return;
        }

        mt_srand(5050);

        $sellers = [...$this->demoStores()];
        $demo = User::query()->where('username', 'seller_demo')->first()?->sellerProfile;
        if ($demo !== null) {
            $sellers[] = $demo;
        }

        DB::transaction(function () use ($sellers): void {
            $rows = [];
            $categories = GameCategory::query()->visible()->with(['type', 'game'])->orderBy('id')->get();

            foreach ($categories as $gameCategory) {
                $products = Product::query()->active()->where('game_category_id', $gameCategory->id)->get();
                $methods = OfferRules::allowedMethods($gameCategory);

                if ($products->isNotEmpty()) {
                    foreach ($products as $product) {
                        $base = $product->msrp_amount ?? mt_rand(5, 60) * 100;
                        foreach ($this->pick($sellers, mt_rand(1, 3)) as $i => $seller) {
                            $this->pass($seller, $gameCategory);
                            $rows[] = $this->row($seller, $gameCategory, $product, $product->translate('name', 'en'), $methods[$i % count($methods)], (int) round($base * mt_rand(70, 100) / 100) - 1, $product->attribute_values ?? []);
                        }
                    }
                } elseif ($gameCategory->game->is_hot) {
                    // Unit categories (currency, items, accounts, boosting…): a few offers per hot game.
                    foreach ($this->pick($sellers, 3) as $i => $seller) {
                        $this->pass($seller, $gameCategory);
                        $attributes = $this->attributesFor($gameCategory);
                        $title = $gameCategory->title('en').' — '.['fast delivery', 'best value', 'safe and verified'][$i];
                        $rows[] = $this->row($seller, $gameCategory, null, $title, $methods[$i % count($methods)], mt_rand(3, 90) * 100 - 1, $attributes);
                    }
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('offers')->insert($chunk);
            }
        });

        if ($demo !== null) {
            $this->demoStorage($demo);
        }

        app(RecountCatalog::class)->handle();
    }

    /**
     * @return list<SellerProfile>
     */
    private function demoStores(): array
    {
        $profiles = [];
        $levels = SellerLevel::query()->pluck('id', 'code');

        foreach (self::STORES as [$username, $store, $level]) {
            $user = User::query()->where('username', $username)->first() ?? app(RegisterUser::class)->handle([
                'username' => $username,
                'name' => $store,
                'email' => "{$username}@x2key.test",
                'password' => 'password',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $profile = SellerProfile::query()->firstOrNew(['user_id' => $user->id]);
            $profile->fill(['store_name' => $store, 'slug' => Str::slug($store), 'seller_level_id' => $levels[$level] ?? null, 'bio' => ['en' => "{$store}: independent seller of game keys, top-ups and accounts."]]);
            $profile->status = SellerStatus::Active;
            $profile->activated_at = now()->subDays(mt_rand(30, 700));
            $profile->save();

            SellerStats::query()->firstOrCreate(['seller_profile_id' => $profile->id], ['positive_rate_bps' => mt_rand(9200, 9990), 'rating_count' => mt_rand(20, 4000)]);
            SellerStoreMedia::query()->firstOrCreate(['seller_profile_id' => $profile->id], ['languages' => [['code' => 'en', 'level' => 'fluent']], 'featured_offer_ids' => []]);
            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * @param  list<SellerProfile>  $sellers
     * @return list<SellerProfile>
     */
    private function pick(array $sellers, int $count): array
    {
        $keys = (array) array_rand($sellers, min($count, count($sellers)));

        return array_map(static fn (int|string $k): SellerProfile => $sellers[$k], $keys);
    }

    private function pass(SellerProfile $seller, GameCategory $gameCategory): void
    {
        $application = SellerServiceApplication::query()->firstOrNew(['seller_profile_id' => $seller->id, 'game_category_id' => $gameCategory->id]);
        if ($application->exists && $application->status === ServiceApplicationStatus::Passed) {
            return;
        }
        $application->status = ServiceApplicationStatus::Passed;
        $application->audited_at = now();
        $application->save();
    }

    /** @var array<int, list<int>> enabled service type ids per category type */
    private array $services = [];

    /** A demo offer in a category type with service types names one of them (ADR-032). */
    private function serviceFor(GameCategory $gameCategory): ?int
    {
        $ids = $this->services[$gameCategory->category_type_id] ??= array_values(ServiceType::availableFor($gameCategory->category_type_id)->map(static fn (ServiceType $s): int => $s->id)->all());

        return $ids === [] ? null : $ids[mt_rand(0, count($ids) - 1)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function row(SellerProfile $seller, GameCategory $gameCategory, ?Product $product, string $title, DeliveryMethod $method, int $price, array $attributes): array
    {
        $now = now();
        $insurance = mt_rand(0, 3) === 0 ? 7 : 0;

        return [
            'public_id' => strtolower((string) Str::ulid()),
            'seller_profile_id' => $seller->id,
            'game_category_id' => $gameCategory->id,
            'product_id' => $product?->id,
            'service_type_id' => $this->serviceFor($gameCategory),
            'title' => mb_substr($title, 0, 160),
            'description' => json_encode(['en' => 'Original demo listing. Delivered by the seller after payment; contact through order chat only.']),
            'delivery_method' => $method->value,
            'fulfillment' => Fulfillment::Manual->value,
            'storage_group_id' => null,
            'attribute_values' => $attributes === [] ? null : json_encode($attributes),
            'attributes_hash' => SaveOffer::attributesHash($product?->id, $gameCategory->id, $method, $attributes),
            'unit_price_amount' => max(99, $price),
            'currency' => 'USD',
            'min_qty' => 1,
            'max_qty' => null,
            'unit_label' => null,
            'integer_multiple' => false,
            'quantity_tiers' => null,
            'delivery_time_minutes' => OfferRules::DELIVERY_TIMES[mt_rand(0, 5)],
            'manual_stock_qty' => mt_rand(3, 200),
            'sold_count' => mt_rand(0, 500),
            'status' => OfferStatus::Active->value,
            'account_recovery_protection_days' => $method === DeliveryMethod::AccountTransfer ? 30 : null,
            'insurance_days' => $insurance,
            'insurance_mode' => $insurance > 0 ? 'optional' : 'none',
            'sort_weight' => 0,
            'acquisition_cost_amount' => null,
            'publish_mode' => 'immediate',
            'listing_days' => 30,
            'expires_at' => $now->copy()->addDays(mt_rand(1, 30)),
            'published_at' => $now,
            'status_reason' => null,
            'duplicate_of_offer_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }

    /**
     * Values that satisfy the game×category schema (first option, small numbers, yes).
     *
     * @return array<string, mixed>
     */
    private function attributesFor(GameCategory $gameCategory): array
    {
        $values = [];
        foreach ($gameCategory->attributeSchema()->fields() as $field) {
            $values[$field['key']] = match ($field['type']) {
                AttributeType::Select => $field['options'][mt_rand(0, count($field['options']) - 1)]['value'],
                AttributeType::MultiSelect => [$field['options'][0]['value']],
                AttributeType::Number => mt_rand(1, 100),
                AttributeType::Boolean => true,
                AttributeType::Text => 'Demo',
            };
        }

        return $gameCategory->attributeSchema()->normalise($values);
    }

    /** seller_demo: one healthy key group and one low group linked to auto-delivered offers. */
    private function demoStorage(SellerProfile $demo): void
    {
        $user = $demo->user;
        $import = app(ImportStorageItems::class);

        $healthy = StorageGroup::query()->create(['seller_profile_id' => $demo->id, 'name' => 'Steam keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 5]);
        $low = StorageGroup::query()->create(['seller_profile_id' => $demo->id, 'name' => 'Gift card codes', 'item_type' => StorageItemType::GiftCard, 'low_stock_threshold' => 10]);

        $codes = static fn (int $n, string $prefix): string => implode("\n", array_map(static fn (): string => $prefix.'-'.strtoupper(Str::random(5)).'-'.strtoupper(Str::random(5)), range(1, $n)));
        $import->handle($healthy, $user, 'paste', StoragePayload::parse(StorageItemType::Key, $codes(25, 'DEMO')));
        $import->handle($low, $user, 'paste', StoragePayload::parse(StorageItemType::GiftCard, $codes(3, 'GIFT')));

        // Link two of the demo seller's key/code offers to the groups.
        $offers = DB::table('offers')->where('seller_profile_id', $demo->id)->whereIn('delivery_method', ['digital_key', 'code'])->orderBy('id')->limit(2)->pluck('id')->all();
        foreach ($offers as $i => $id) {
            DB::table('offers')->where('id', $id)->update(['fulfillment' => Fulfillment::Auto->value, 'storage_group_id' => $i === 0 ? $healthy->id : $low->id, 'manual_stock_qty' => null]);
        }

        foreach ([$healthy, $low] as $group) {
            app(RefreshStorageStock::class)->handle($group);
        }
    }
}
