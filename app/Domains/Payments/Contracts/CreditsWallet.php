<?php

declare(strict_types=1);

namespace App\Domains\Payments\Contracts;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Exceptions\InsufficientCredits;

/**
 * The buyer's Credits Balance as a payment source (spec 9.2), backed by the ledger (LedgerCreditsWallet:
 * CREDITS_SPENT / CREDITS_RESTORED journals with row-locked overdraw checks — ADR-016). Both writes are
 * idempotent per key and post in the caller's transaction; `$order` becomes the journal's source.
 */
interface CreditsWallet
{
    /** Spendable balance in minor units. */
    public function available(User $user, string $currency): int;

    /** @throws InsufficientCredits */
    public function spend(User $user, int $amount, string $currency, string $idempotencyKey, ?Order $order = null): void;

    /** Gives credits back after a cancellation before capture or a refund to credits. */
    public function restore(User $user, int $amount, string $currency, string $idempotencyKey, ?Order $order = null): void;
}
