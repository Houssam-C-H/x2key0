<?php

declare(strict_types=1);

namespace App\Domains\Payments\Contracts;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Exceptions\InsufficientCredits;

/**
 * The buyer's Credits Balance as a payment source (spec 9.2). From M7 it is backed by the ledger
 * (`CREDITS_SPENT` / refund journals, row-locked balance checks); until then UnfundedCreditsWallet reports a
 * zero balance — there is no way to fund credits before M7 anyway (D8: no top-up).
 * Both writes are idempotent per key: replaying a key changes nothing.
 */
interface CreditsWallet
{
    /** Spendable balance in minor units. */
    public function available(User $user, string $currency): int;

    /** @throws InsufficientCredits */
    public function spend(User $user, int $amount, string $currency, string $idempotencyKey): void;

    /** Gives credits back after a cancellation before capture or a refund to credits. */
    public function restore(User $user, int $amount, string $currency, string $idempotencyKey): void;
}
