<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Payouts\Actions\ManagePayoutMethods;
use App\Domains\Payouts\Models\PayoutMethod;
use App\Domains\Sellers\Models\SellerProfile;
use Database\Seeders\PayoutMethodCatalogSeeder;
use Illuminate\Support\Str;

/** A seller who may withdraw (identity verified, 2FA, payouts enabled) with money in the Available Balance. */
final class Payouts
{
    /**
     * @return array{seller: User, profile: SellerProfile, method: PayoutMethod}
     */
    public static function seller(int $available = 10000): array
    {
        (new PayoutMethodCatalogSeeder)->run();
        $profile = SellerProfile::factory()->create(['payouts_enabled' => true]);
        $user = $profile->user;
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        Certification::query()->create(['user_id' => $user->id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Verified, 'provider' => 'fake', 'verified_at' => now()]);

        if ($available > 0) {
            self::fund($user, $available);
        }

        $method = app(ManagePayoutMethods::class)->add($user->refresh(), 'paypal', 'USD', ['email' => 'seller@example.com']);

        return ['seller' => $user->refresh(), 'profile' => $profile, 'method' => $method];
    }

    /** Puts money in the seller's Available Balance (as released earnings would). */
    public static function fund(User $user, int $cents): void
    {
        app(PostJournal::class)->handle(new JournalDraft(
            type: JournalType::ManualAdjustment,
            idempotencyKey: 'TEST_FUND:'.Str::ulid(),
            lines: [
                [AccountRef::platform(AccountCode::Bank, 'USD'), $cents],
                [AccountRef::user(AccountCode::SellerAvailable, $user->id, 'USD'), -$cents],
            ],
        ));
    }
}
