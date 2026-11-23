<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Data;

use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\AccountType;
use InvalidArgumentException;

/** Identifies one ledger account: code (with provider suffix for PSP clearing), owner and currency. */
final readonly class AccountRef
{
    private function __construct(
        public string $code,
        public AccountType $type,
        public string $ownerType,
        public int $ownerId,
        public string $currency,
    ) {}

    public static function platform(AccountCode $code, string $currency): self
    {
        if ($code->isUserOwned() || $code === AccountCode::PspClearing) {
            throw new InvalidArgumentException("{$code->value} is not a plain platform account.");
        }

        return new self($code->value, $code->type(), 'platform', 0, $currency);
    }

    public static function user(AccountCode $code, int $userId, string $currency): self
    {
        if (! $code->isUserOwned() || $userId <= 0) {
            throw new InvalidArgumentException("{$code->value} is not a user account.");
        }

        return new self($code->value, $code->type(), 'user', $userId, $currency);
    }

    public static function pspClearing(string $provider, string $currency): self
    {
        if (preg_match('/^[a-z0-9_]{1,32}$/', $provider) !== 1) {
            throw new InvalidArgumentException('Invalid provider code.');
        }

        return new self(AccountCode::PspClearing->value.':'.$provider, AccountType::Asset, 'platform', 0, $currency);
    }

    public function key(): string
    {
        return "{$this->code}|{$this->ownerType}|{$this->ownerId}|{$this->currency}";
    }
}
