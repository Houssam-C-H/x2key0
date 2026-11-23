<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Sellers\Models\SellerStoreMedia;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seller activation via the Certification Center (spec 3.2 / 6.3): creates the store on first use
 * (name and slug from the username, entry level, empty stats), moves it inactive → active, and records
 * the "Store" certification as verified. Calling it again for an active seller changes nothing.
 */
final class ActivateSeller
{
    public function __construct(private readonly SellerProfileStateMachine $machine) {}

    public function handle(User $user): SellerProfile
    {
        if ($user->status !== UserStatus::Active) {
            throw SellerException::because('seller.error.account_inactive');
        }

        $existing = $user->sellerProfile()->first();

        if ($existing !== null && $existing->status !== SellerStatus::Inactive) {
            if ($existing->status === SellerStatus::Banned) {
                throw SellerException::because('seller.error.banned');
            }

            return $existing;
        }

        return DB::transaction(function () use ($user, $existing): SellerProfile {
            $profile = $existing ?? $this->createProfile($user);
            $profile = $this->machine->activate($profile, $user);

            Certification::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => CertificationType::Store],
                ['status' => CertificationStatus::Verified, 'provider' => 'system', 'verified_at' => now(), 'rejected_reason' => null],
            );

            return $profile;
        });
    }

    private function createProfile(User $user): SellerProfile
    {
        $profile = new SellerProfile([
            'user_id' => $user->id,
            'store_name' => $this->uniqueStoreName($user->username),
            'slug' => $this->uniqueSlug($user->username),
            'country' => $user->country,
            'seller_level_id' => SellerLevel::entry()->id,
            'level_locked' => false,
            'payouts_enabled' => false,
        ]);
        $profile->status = SellerStatus::Inactive;
        $profile->save();

        SellerStats::query()->create(['seller_profile_id' => $profile->id]);
        SellerStoreMedia::query()->create(['seller_profile_id' => $profile->id, 'languages' => [], 'featured_offer_ids' => []]);

        return $profile;
    }

    private function uniqueStoreName(string $base): string
    {
        $base = mb_substr($base, 0, 34);
        $name = $base;

        for ($i = 2; SellerProfile::query()->whereRaw('lower(store_name) = ?', [mb_strtolower($name)])->exists(); $i++) {
            $name = "{$base}-{$i}";
        }

        return $name;
    }

    private function uniqueSlug(string $base): string
    {
        $base = Str::limit(Str::slug($base), 40, '') ?: 'store';
        $slug = $base;

        for ($i = 2; SellerProfile::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
