<?php

declare(strict_types=1);

namespace App\Domains\Payments\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Exceptions\InsufficientCredits;
use LogicException;

/**
 * Credits Balance before the ledger exists (M6, ADR-016): always empty, so checkout never offers credits.
 * Replaced by the ledger-backed wallet in M7.
 */
final class UnfundedCreditsWallet implements CreditsWallet
{
    public function available(User $user, string $currency): int
    {
        return 0;
    }

    public function spend(User $user, int $amount, string $currency, string $idempotencyKey): void
    {
        if ($amount > 0) {
            throw new InsufficientCredits;
        }
    }

    public function restore(User $user, int $amount, string $currency, string $idempotencyKey): void
    {
        if ($amount > 0) {
            throw new LogicException('Credits cannot be restored before the ledger exists (M7).');
        }
    }
}
