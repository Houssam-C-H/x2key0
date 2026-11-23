<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Data;

use App\Domains\Ledger\Enums\JournalType;

/**
 * A journal to post: lines (account, signed minor amount; debit +, credit −), a unique idempotency key, the
 * source it belongs to, and the user-owned liabilities that must not be overdrawn by this posting
 * (invariant 4.3 #2: a user's spend never takes their balance below zero).
 */
final readonly class JournalDraft
{
    /**
     * @param  list<array{0: AccountRef, 1: int}>  $lines
     * @param  list<AccountRef>  $noOverdraw
     */
    public function __construct(
        public JournalType $type,
        public string $idempotencyKey,
        public array $lines,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $description = null,
        public array $noOverdraw = [],
        public string $createdByType = 'system',
        public ?int $createdById = null,
    ) {}
}
