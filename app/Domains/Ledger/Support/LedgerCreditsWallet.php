<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Exceptions\InsufficientCredits;

/**
 * Credits Balance backed by the ledger (spec 7.2, ADR-016): the balance is what BUYER_CREDITS(user) owes.
 * Spending posts CREDITS_SPENT (BUYER_CREDITS +, ORDER_ESCROW −) under the balance row lock with an
 * overdraw guard, so concurrent spends can never take the balance below zero; restoring posts
 * CREDITS_RESTORED. Both are idempotent by key.
 */
final class LedgerCreditsWallet implements CreditsWallet
{
    public function __construct(private readonly PostJournal $post) {}

    public function available(User $user, string $currency): int
    {
        return max(0, Accounts::owed(AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency)));
    }

    public function spend(User $user, int $amount, string $currency, string $idempotencyKey, ?Order $order = null): void
    {
        $credits = AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency);

        try {
            $this->post->handle(new JournalDraft(
                type: JournalType::CreditsSpent,
                idempotencyKey: $idempotencyKey,
                lines: [[$credits, $amount], [AccountRef::platform(AccountCode::OrderEscrow, $currency), -$amount]],
                sourceType: $order === null ? 'user' : 'order',
                sourceId: $order->id ?? $user->id,
                description: $order === null ? null : "Order {$order->number}",
                noOverdraw: [$credits],
            ));
        } catch (BalanceOverdrawn) {
            throw new InsufficientCredits;
        }
    }

    public function restore(User $user, int $amount, string $currency, string $idempotencyKey, ?Order $order = null): void
    {
        $this->post->handle(new JournalDraft(
            type: JournalType::CreditsRestored,
            idempotencyKey: $idempotencyKey,
            lines: [[AccountRef::platform(AccountCode::OrderEscrow, $currency), $amount], [AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency), -$amount]],
            sourceType: $order === null ? 'user' : 'order',
            sourceId: $order->id ?? $user->id,
            description: $order === null ? null : "Order {$order->number} closed",
        ));
    }
}
