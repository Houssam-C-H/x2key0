<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\MarketingEmail;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\DB;

/**
 * `marketing:settle` (spec 14.3, daily): bills yesterday's and older unsettled sends from the seller's Available
 * balance — MARKETING_FEE: SELLER_AVAILABLE + total, REV_MARKETING − total (idempotent per send). A seller
 * without enough Available balance stays "Unsettled" and is retried the next day.
 */
final class SettleMarketingEmails
{
    public function __construct(private readonly PostJournal $post) {}

    /**
     * @return array{settled: int, unsettled: int}
     */
    public function handle(): array
    {
        $settled = 0;
        $left = 0;

        MarketingEmail::query()->where('status', 'unsettled')->where('sent_at', '<', now()->startOfDay())->orderBy('id')
            ->chunkById(200, function ($emails) use (&$settled, &$left): void {
                foreach ($emails as $email) {
                    $this->settle($email) ? $settled++ : $left++;
                }
            });

        return ['settled' => $settled, 'unsettled' => $left];
    }

    public function settle(MarketingEmail $email): bool
    {
        if ($email->total_amount <= 0) {
            $email->forceFill(['status' => 'settled', 'settled_at' => now()])->save();

            return true;
        }

        $userId = SellerProfile::query()->whereKey($email->seller_profile_id)->value('user_id');
        $sellerUserId = is_numeric($userId) ? (int) $userId : 0;

        try {
            DB::transaction(function () use ($email, $sellerUserId): void {
                $available = AccountRef::user(AccountCode::SellerAvailable, $sellerUserId, 'USD');
                $this->post->handle(new JournalDraft(
                    type: JournalType::MarketingFee,
                    idempotencyKey: "MARKETING_FEE:email:{$email->id}",
                    lines: [
                        [$available, $email->total_amount],
                        [AccountRef::platform(AccountCode::RevMarketing, 'USD'), -$email->total_amount],
                    ],
                    sourceType: 'marketing_email',
                    sourceId: $email->id,
                    description: "Marketing e-mail {$email->public_id}",
                    noOverdraw: [$available],
                ));
                $email->forceFill(['status' => 'settled', 'settled_at' => now()])->save();
            });
        } catch (BalanceOverdrawn) {
            return false;
        }

        return true;
    }
}
