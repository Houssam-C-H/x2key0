<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Exceptions\InsufficientCredits;

/**
 * Credits Balance stand-in until the ledger (M7): per-user balances in memory, idempotent per key.
 */
final class InMemoryCreditsWallet implements CreditsWallet
{
    /** @var array<int, int> */
    public array $balances = [];

    /** @var array<string, true> */
    public array $keys = [];

    public function fund(User $user, int $amount): self
    {
        $this->balances[$user->id] = ($this->balances[$user->id] ?? 0) + $amount;

        return $this;
    }

    public function available(User $user, string $currency): int
    {
        return $this->balances[$user->id] ?? 0;
    }

    public function spend(User $user, int $amount, string $currency, string $idempotencyKey): void
    {
        if (isset($this->keys[$idempotencyKey])) {
            return;
        }
        if ($amount > $this->available($user, $currency)) {
            throw new InsufficientCredits;
        }
        $this->balances[$user->id] = $this->available($user, $currency) - $amount;
        $this->keys[$idempotencyKey] = true;
    }

    public function restore(User $user, int $amount, string $currency, string $idempotencyKey): void
    {
        if (isset($this->keys[$idempotencyKey])) {
            return;
        }
        $this->balances[$user->id] = $this->available($user, $currency) + $amount;
        $this->keys[$idempotencyKey] = true;
    }
}
