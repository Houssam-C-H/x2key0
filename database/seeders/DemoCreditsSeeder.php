<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use Illuminate\Database\Seeder;

/**
 * Local demo only: $25.00 promotional credits for buyer_demo, posted like any promotion would be
 * (PROMO_CREDIT: EXP_PROMOTIONS +, BUYER_CREDITS −), so the credits toggle can be tried at checkout.
 */
final class DemoCreditsSeeder extends Seeder
{
    public function run(): void
    {
        $buyer = User::query()->where('username', 'buyer_demo')->first();
        if ($buyer === null) {
            return;
        }

        app(PostJournal::class)->handle(new JournalDraft(
            type: JournalType::PromoCredit,
            idempotencyKey: "PROMO_CREDIT:user:{$buyer->id}:demo",
            lines: [
                [AccountRef::platform(AccountCode::ExpPromotions, 'USD'), 2500],
                [AccountRef::user(AccountCode::BuyerCredits, $buyer->id, 'USD'), -2500],
            ],
            sourceType: 'user',
            sourceId: $buyer->id,
            description: 'Welcome credits (demo)',
        ));
    }
}
