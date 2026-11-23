<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Events\HoldReleased;
use App\Domains\Ledger\Models\Hold;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * `holds:release` (spec Part 19, every 15 min): money whose hold ended moves on.
 *
 * - settlement / insurance holds → HOLD_RELEASE: SELLER_PENDING + amount, SELLER_AVAILABLE − (amount − reserve),
 *   SELLER_RESERVE − reserve; the reserve (rolling reserve of the seller level) becomes its own hold that
 *   comes back after `reserve_release_days`;
 * - reserve holds → RESERVE_RELEASE: SELLER_RESERVE + amount, SELLER_AVAILABLE − amount.
 *
 * Idempotent: each hold is locked, journaled under `HOLD_RELEASE:hold:{id}` / `RESERVE_RELEASE:hold:{id}` and
 * marked released in one transaction; the journal key makes a retry harmless.
 */
final class ReleaseHolds
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Settings $settings,
    ) {}

    public function handle(): int
    {
        $released = 0;
        $events = [];

        Hold::query()->due()->orderBy('id')->select('id')->chunkById(100, function ($rows) use (&$released, &$events): void {
            foreach ($rows as $row) {
                $result = DB::transaction(function () use ($row): ?Hold {
                    /** @var Hold|null $hold */
                    $hold = Hold::query()->lockForUpdate()->find($row->id);

                    if ($hold === null || $hold->released_at !== null || $hold->frozen_by !== null || $hold->release_at->isFuture()) {
                        return null;
                    }

                    $hold->kind === Hold::RESERVE ? $this->releaseReserve($hold) : $this->releaseSettlement($hold);

                    return $hold;
                });

                if ($result !== null) {
                    $released++;
                    $events[] = $result;
                }
            }
        });

        foreach ($events as $hold) {
            HoldReleased::dispatch($hold->id);
        }

        return $released;
    }

    private function releaseSettlement(Hold $hold): void
    {
        $currency = $hold->currency;
        $reserve = Amount::toMinor(Amount::bps(Amount::ofMinor($hold->amount, $currency), $hold->reserve_bps));

        $lines = [
            [AccountRef::user(AccountCode::SellerPending, $hold->owner_id, $currency), $hold->amount],
            [AccountRef::user(AccountCode::SellerAvailable, $hold->owner_id, $currency), -($hold->amount - $reserve)],
        ];
        if ($reserve > 0) {
            $lines[] = [AccountRef::user(AccountCode::SellerReserve, $hold->owner_id, $currency), -$reserve];
        }

        $journal = $this->post->handle(new JournalDraft(
            type: JournalType::HoldRelease,
            idempotencyKey: "HOLD_RELEASE:hold:{$hold->id}",
            lines: $lines,
            sourceType: 'hold',
            sourceId: $hold->id,
            description: 'Earnings released',
        ));

        $hold->forceFill(['released_at' => now(), 'journal_id' => $journal->id])->save();

        if ($reserve > 0) {
            (new Hold)->forceFill([
                'owner_type' => $hold->owner_type,
                'owner_id' => $hold->owner_id,
                'order_id' => $hold->order_id,
                'kind' => Hold::RESERVE,
                'seq' => $hold->seq,
                'amount' => $reserve,
                'reserve_bps' => 0,
                'currency' => $currency,
                'release_at' => now()->addDays($this->settings->int('reserve_release_days')),
                'source_hold_id' => $hold->id,
            ])->save();
        }
    }

    private function releaseReserve(Hold $hold): void
    {
        $currency = $hold->currency;

        $journal = $this->post->handle(new JournalDraft(
            type: JournalType::ReserveRelease,
            idempotencyKey: "RESERVE_RELEASE:hold:{$hold->id}",
            lines: [
                [AccountRef::user(AccountCode::SellerReserve, $hold->owner_id, $currency), $hold->amount],
                [AccountRef::user(AccountCode::SellerAvailable, $hold->owner_id, $currency), -$hold->amount],
            ],
            sourceType: 'hold',
            sourceId: $hold->id,
            description: 'Reserve returned',
        ));

        $hold->forceFill(['released_at' => now(), 'journal_id' => $journal->id])->save();
    }
}
