<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Sellers\Models\SellerStoreMedia;
use Database\Seeders\SellerLevelSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Active seller with stats and media rows. Tests that exercise activation itself use ActivateSeller.
 *
 * @extends Factory<SellerProfile>
 */
final class SellerProfileFactory extends Factory
{
    protected $model = SellerProfile::class;

    public function definition(): array
    {
        $name = 'store'.Str::lower(Str::random(6));

        return [
            'user_id' => User::factory()->withVerifiedPhone(),
            'store_name' => $name,
            'slug' => $name,
            'logo_path' => null,
            'banner_path' => null,
            'bio' => null,
            'country' => null,
            'status' => SellerStatus::Active,
            'status_reason' => null,
            'seller_level_id' => fn (): int => self::entryLevelId(),
            'level_locked' => false,
            'payouts_enabled' => false,
            'storage_enabled' => true,
            'merchant_manager_id' => null,
            'auto_reply' => null,
            'store_notice' => null,
            'vacation_until' => null,
            'activated_at' => now(),
        ];
    }

    /** Seeds the reference levels on first use so tests need no extra setup. */
    public static function entryLevelId(): int
    {
        if (! SellerLevel::query()->exists()) {
            (new SellerLevelSeeder)->run();
        }

        return SellerLevel::entry()->id;
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SellerProfile $profile): void {
            SellerStats::query()->firstOrCreate(['seller_profile_id' => $profile->id]);
            SellerStoreMedia::query()->firstOrCreate(['seller_profile_id' => $profile->id], ['languages' => [], 'featured_offer_ids' => []]);
        });
    }

    public function status(SellerStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status, 'activated_at' => $status === SellerStatus::Inactive ? null : now()]);
    }

    public function level(string $code): static
    {
        self::entryLevelId();

        return $this->state(fn (): array => ['seller_level_id' => SellerLevel::query()->where('code', $code)->firstOrFail()->id]);
    }
}
