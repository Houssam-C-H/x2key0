<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Support\Accounts;
use Illuminate\Support\Str;

/** Ledger helpers for tests: fund a buyer's credits as a promotion would, read balances. */
final class Ledger
{
    public static function fundCredits(User $user, int $cents, string $currency = 'USD'): void
    {
        app(PostJournal::class)->handle(new JournalDraft(
            type: JournalType::PromoCredit,
            idempotencyKey: 'PROMO_CREDIT:user:'.$user->id.':'.Str::random(8),
            lines: [
                [AccountRef::platform(AccountCode::ExpPromotions, $currency), $cents],
                [AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency), -$cents],
            ],
            sourceType: 'user',
            sourceId: $user->id,
        ));
    }

    public static function credits(User $user, string $currency = 'USD'): int
    {
        return Accounts::owed(AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency));
    }

    /** What the platform holds in escrow for orders (liability, shown positive). */
    public static function escrow(string $currency = 'USD'): int
    {
        return Accounts::owed(AccountRef::platform(AccountCode::OrderEscrow, $currency));
    }

    public static function feeRevenue(string $currency = 'USD'): int
    {
        return Accounts::owed(AccountRef::platform(AccountCode::RevBuyerFee, $currency));
    }

    public static function clearing(string $provider = 'fake', string $currency = 'USD'): int
    {
        return Accounts::balance(AccountRef::pspClearing($provider, $currency));
    }
}
