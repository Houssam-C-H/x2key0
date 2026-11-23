<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Actions\RegisterUser;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\ActivateSeller;
use App\Domains\Sellers\Actions\ApplyForService;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([UserLevelSeeder::class, RoleSeeder::class, SellerLevelSeeder::class, CatalogSeeder::class, PaymentMethodSeeder::class, PointsRuleSeeder::class, ViolationRuleSeeder::class, CannedReplySeeder::class, PayoutMethodCatalogSeeder::class]);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Local demo accounts (password: "password"). The admin must enable 2FA before /admin opens.
        $register = app(RegisterUser::class);

        foreach ([['admin', StaffRole::Admin], ['buyer', null], ['seller', null]] as [$username, $role]) {
            if (User::query()->where('username', $username.'_demo')->exists()) {
                continue;
            }

            $user = $register->handle([
                'username' => $username.'_demo',
                'name' => ucfirst($username),
                'email' => $username.'@x2key.test',
                'password' => 'password',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($role !== null) {
                $user->assignRole($role->value);
            }
        }

        $this->seedDemoSeller();
        $this->call([OfferSeeder::class, DemoCreditsSeeder::class]);
    }

    /** seller_demo: certified (email, phone, identity), active store, a few passed services. */
    private function seedDemoSeller(): void
    {
        $user = User::query()->where('username', 'seller_demo')->firstOrFail();

        if ($user->sellerProfile()->exists()) {
            return;
        }

        $user->forceFill(['phone' => '+212600000001', 'phone_verified_at' => now()])->save();
        Certification::query()->create([
            'user_id' => $user->id,
            'type' => CertificationType::Identity,
            'status' => CertificationStatus::Verified,
            'provider' => 'fake',
            'verified_at' => now(),
        ]);

        $profile = app(ActivateSeller::class)->handle($user->refresh());
        $profile->forceFill([
            'store_name' => 'Pixel Vault',
            'bio' => ['en' => 'Fast delivery on game keys and top-ups.', 'fr' => 'Livraison rapide de clés et recharges.'],
        ])->save();

        $apply = app(ApplyForService::class);
        foreach (GameCategory::query()->visible()->orderBy('id')->limit(4)->get() as $gameCategory) {
            $application = $apply->handle($profile, $gameCategory->id);
            $application->forceFill(['status' => ServiceApplicationStatus::Passed, 'audited_at' => now()])->save();
        }
    }
}
