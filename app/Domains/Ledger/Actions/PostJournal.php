<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Support\Accounts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ledger postings (spec 7.4). One call = one journal, all or nothing:
 *
 * 1. Idempotent: a key already posted returns that journal (same type required) and changes nothing.
 * 2. Every line has a non-zero amount in its account's currency; lines sum to zero per currency.
 * 3. Balance rows are locked in account-id order (no deadlocks between journals), updated, and the
 *    accounts listed in `noOverdraw` must still owe the user ≥ 0 afterwards (invariant 4.3 #2).
 *
 * Runs in its own (possibly nested) transaction, so callers post in the same transaction as the business
 * change that causes the money movement.
 */
final class PostJournal
{
    public function handle(JournalDraft $draft): LedgerJournal
    {
        $existing = self::existing($draft);
        if ($existing !== null) {
            return $existing;
        }

        $lines = self::merge($draft->lines);

        try {
            return DB::transaction(fn (): LedgerJournal => $this->post($draft, $lines));
        } catch (UniqueConstraintViolationException $e) {
            // The same key was posted concurrently: the first one wins.
            return self::existing($draft) ?? throw $e;
        }
    }

    /**
     * @param  array<string, array{ref: AccountRef, amount: int}>  $lines
     */
    private function post(JournalDraft $draft, array $lines): LedgerJournal
    {
        $ids = [];
        foreach ($lines as $key => $line) {
            $ids[$key] = Accounts::id($line['ref']);
        }

        $sorted = array_values(array_unique($ids));
        sort($sorted);
        $now = now();

        DB::table('ledger_balances')->insertOrIgnore(array_map(static fn (int $id): array => ['account_id' => $id, 'balance' => 0, 'updated_at' => $now], $sorted));
        DB::table('ledger_balances')->whereIn('account_id', $sorted)->orderBy('account_id')->lockForUpdate()->get();

        $journal = new LedgerJournal;
        $journal->forceFill([
            'type' => $draft->type,
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'description' => $draft->description === null ? null : mb_substr($draft->description, 0, 255),
            'created_by_type' => $draft->createdByType,
            'created_by_id' => $draft->createdById,
            'idempotency_key' => $draft->idempotencyKey,
            'posted_at' => $now,
        ])->save();

        $rows = [];
        foreach ($lines as $key => $line) {
            $rows[] = ['journal_id' => $journal->id, 'account_id' => $ids[$key], 'amount' => $line['amount'], 'currency' => $line['ref']->currency, 'created_at' => $now];
            DB::table('ledger_balances')->where('account_id', $ids[$key])->increment('balance', $line['amount'], ['updated_at' => $now]);
        }
        DB::table('ledger_postings')->insert($rows);

        foreach ($draft->noOverdraw as $ref) {
            if (Accounts::owed($ref) < 0) {
                throw new BalanceOverdrawn("Posting would overdraw {$ref->key()}.");
            }
        }

        return $journal;
    }

    /**
     * Validates the lines and merges repeated accounts (zero results dropped).
     *
     * @param  list<array{0: AccountRef, 1: int}>  $lines
     * @return array<string, array{ref: AccountRef, amount: int}>
     */
    private static function merge(array $lines): array
    {
        $merged = [];
        $sums = [];

        foreach ($lines as [$ref, $amount]) {
            if ($amount === 0) {
                continue;
            }
            $key = $ref->key();
            $merged[$key] = ['ref' => $ref, 'amount' => ($merged[$key]['amount'] ?? 0) + $amount];
            $sums[$ref->currency] = ($sums[$ref->currency] ?? 0) + $amount;
        }

        foreach ($sums as $currency => $sum) {
            if ($sum !== 0) {
                throw new LedgerException("Journal does not sum to zero in {$currency} ({$sum}).");
            }
        }

        $merged = array_filter($merged, static fn (array $l): bool => $l['amount'] !== 0);
        if ($merged === []) {
            throw new LedgerException('Journal has no lines.');
        }

        return $merged;
    }

    private static function existing(JournalDraft $draft): ?LedgerJournal
    {
        $journal = LedgerJournal::query()->where('idempotency_key', $draft->idempotencyKey)->first();

        if ($journal !== null && $journal->type !== $draft->type) {
            throw new LedgerException("Idempotency key {$draft->idempotencyKey} was used by a {$journal->type->value} journal.");
        }

        return $journal;
    }
}
