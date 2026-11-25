<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use App\Domains\Identity\Models\UserSecurity;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Browser test fixtures (tests/e2e): three known accounts and one buyable offer, reset on every run.
 * Refuses to run in production.
 *
 * - e2e_buyer / e2e_seller (open store, silver level, one approved video-game category with a live offer) /
 *   e2e_admin (admin, 2FA with the fixed secret E2E_TOTP_SECRET so the tests can compute codes).
 */
final class E2ePrepare extends Command
{
    public const string PASSWORD = 'e2e-Password-123';

    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected $signature = 'e2e:prepare';

    protected $description = 'Create or reset the browser-test accounts and offer (never in production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $buyer = $this->user('e2e_buyer');
        $seller = $this->user('e2e_seller');
        $admin = $this->user('e2e_admin');
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(self::TOTP_SECRET),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([]) ?: '[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        if (! $admin->hasStaffRole(StaffRole::Admin)) {
            $admin->assignRole(StaffRole::Admin->value);
        }

        $profile = SellerProfile::query()->where('user_id', $seller->id)->first()
            ?? SellerProfile::factory()->create(['user_id' => $seller->id, 'store_name' => 'E2E Store', 'slug' => 'e2e-store']);
        $profile->forceFill([
            'status' => SellerStatus::Active,
            'storage_enabled' => true,
            'seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id') ?? $profile->seller_level_id,
        ])->save();

        // A product of a video-game category without buyer inputs, so checkout needs no extra fields.
        $product = Product::query()->active()
            ->whereHas('gameCategory', fn ($q) => $q->visible()->whereNull('buyer_input_schema'))
            ->with('gameCategory')
            ->orderBy('id')
            ->first();
        if ($product === null) {
            $this->error('No active product without buyer inputs: run the catalog seeders first.');

            return self::FAILURE;
        }

        $application = SellerServiceApplication::query()->firstOrNew(['seller_profile_id' => $profile->id, 'game_category_id' => $product->game_category_id]);
        $application->forceFill(['status' => ServiceApplicationStatus::Passed, 'audited_at' => now()])->save();

        $offer = Offer::query()->where('seller_profile_id', $profile->id)->where('product_id', $product->id)->first()
            ?? Offer::factory()->forProduct($product)->create(['seller_profile_id' => $profile->id, 'title' => 'E2E test key']);
        $offer->forceFill([
            'status' => OfferStatus::Active,
            'fulfillment' => Fulfillment::Manual,
            'manual_stock_qty' => 999,
            'unit_price_amount' => 199,
            'quantity_tiers' => [['min_qty' => 5, 'unit_price_amount' => 179], ['min_qty' => 10, 'unit_price_amount' => 159]],
            'expires_at' => now()->addDays(30),
            'published_at' => now(),
        ])->save();

        $this->line(json_encode([
            'password' => self::PASSWORD,
            'totpSecret' => self::TOTP_SECRET,
            'buyer' => $buyer->username,
            'seller' => $seller->username,
            'admin' => $admin->username,
            'offerPath' => $offer->path('en'),
            'offerId' => $offer->public_id,
            'gameCategoryId' => $product->game_category_id,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }

    private function user(string $username): User
    {
        $user = User::query()->where('username', $username)->first()
            ?? User::factory()->create(['username' => $username, 'name' => $username, 'email' => $username.'@x2key.test']);

        $user->forceFill([
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => 'en',
        ])->save();
        UserPreference::query()->firstOrCreate(['user_id' => $user->id]);
        UserSecurity::query()->firstOrCreate(['user_id' => $user->id]);

        return $user;
    }
}
