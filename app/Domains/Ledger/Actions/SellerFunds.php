<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Models\Order;

/**
 * A completed order's seller money while a dispute or chargeback is open (spec 7.1 SELLER_FROZEN, 9.4):
 *
 * - freeze: the order's unreleased holds stop releasing (`frozen_by`); whatever already reached the
 *   Available Balance moves to SELLER_FROZEN ("Not Available"), bounded by what is available;
 * - unfreeze: holds resume and the frozen money goes back to Available;
 * - recover: journal lines taking money back from the seller in this order: frozen money of the case →
 *   the order's unreleased holds (pending / reserve, hold amounts reduced) → Available (may go negative,
 *   the seller then owes the platform).
 *
 * Runs inside the caller's transaction; journal keys are scoped by the case (`dispute:{id}` / `chargeback:{id}`).
 */
final class SellerFunds
{
    public function __construct(private readonly PostJournal $post) {}

    /** Returns the amount moved from Available to Frozen. */
    public function freeze(Order $order, string $case): int
    {
        $order->loadMissing('seller');
        Hold::query()->where('order_id', $order->id)->whereNull('released_at')->whereNull('frozen_by')->update(['frozen_by' => $case]);

        $currency = $order->currency;
        $userId = $order->seller->user_id;
        $available = AccountRef::user(AccountCode::SellerAvailable, $userId, $currency);
        $amount = min(self::releasedShare($order), max(0, Accounts::owed($available)));

        if ($amount <= 0) {
            return 0;
        }

        $this->post->handle(new JournalDraft(
            type: JournalType::DisputeFreeze,
            idempotencyKey: "FUNDS_FREEZE:{$case}",
            lines: [
                [$available, $amount],
                [AccountRef::user(AccountCode::SellerFrozen, $userId, $currency), -$amount],
            ],
            sourceType: 'order',
            sourceId: $order->id,
            description: "Order {$order->number}: funds frozen ({$case})",
            noOverdraw: [$available],
        ));

        return $amount;
    }

    /** Holds frozen by the case resume; `frozen` (what freeze() moved, minus what was recovered) goes back. */
    public function unfreeze(Order $order, string $case, int $frozen): void
    {
        $order->loadMissing('seller');
        Hold::query()->where('order_id', $order->id)->where('frozen_by', $case)->update(['frozen_by' => null]);

        if ($frozen <= 0) {
            return;
        }

        $currency = $order->currency;
        $userId = $order->seller->user_id;

        $this->post->handle(new JournalDraft(
            type: JournalType::DisputeUnfreeze,
            idempotencyKey: "FUNDS_UNFREEZE:{$case}",
            lines: [
                [AccountRef::user(AccountCode::SellerFrozen, $userId, $currency), $frozen],
                [AccountRef::user(AccountCode::SellerAvailable, $userId, $currency), -$frozen],
            ],
            sourceType: 'order',
            sourceId: $order->id,
            description: "Order {$order->number}: funds released ({$case})",
        ));
    }

    /**
     * Lines (debits of seller accounts, summing to `$amount`) for money taken back from the seller, and how much
     * came out of the case's frozen money. Unreleased holds of the order are reduced here.
     *
     * @return array{lines: list<array{0: AccountRef, 1: int}>, fromFrozen: int}
     */
    public function recover(Order $order, int $amount, int $frozenAvailable): array
    {
        $order->loadMissing('seller');
        $currency = $order->currency;
        $userId = $order->seller->user_id;
        $left = $amount;
        $lines = [];

        $fromFrozen = min($left, max(0, $frozenAvailable));
        if ($fromFrozen > 0) {
            $lines[] = [AccountRef::user(AccountCode::SellerFrozen, $userId, $currency), $fromFrozen];
            $left -= $fromFrozen;
        }

        $holds = Hold::query()->where('order_id', $order->id)->whereNull('released_at')->lockForUpdate()
            ->orderByDesc('release_at')->orderByDesc('id')->get();

        foreach ($holds as $hold) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $hold->amount);
            $account = $hold->kind === Hold::RESERVE ? AccountCode::SellerReserve : AccountCode::SellerPending;
            $lines[] = [AccountRef::user($account, $userId, $currency), $take];
            $left -= $take;

            $hold->forceFill(['amount' => $hold->amount - $take] + ($hold->amount === $take ? ['released_at' => now()] : []))->save();
        }

        if ($left > 0) {
            $lines[] = [AccountRef::user(AccountCode::SellerAvailable, $userId, $currency), $left];
        }

        return ['lines' => $lines, 'fromFrozen' => $fromFrozen];
    }

    /** Part of the order's seller net that already left the holds (Available, incl. returned reserves). */
    private static function releasedShare(Order $order): int
    {
        $held = (int) Hold::query()->where('order_id', $order->id)->whereNull('released_at')->sum('amount');

        return max(0, $order->seller_net_amount - $held);
    }
}
