<?php

declare(strict_types=1);

namespace App\Domains\Points\Actions;

use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Points\Models\PointsJournal;
use App\Domains\Points\Support\Points;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of points postings (spec 5.13): same rules as the money ledger — idempotent key,
 * zero-sum, balance rows locked in id order, optional no-overdraw guard on users spending points.
 */
final class PostPointsJournal
{
    /**
     * @param  list<array{0: PointsAccountCode, 1: int, 2: int}>  $lines  [code, owner user id (0 = platform), points]
     * @param  list<int>  $noOverdrawUsers  users whose points may not go below zero (spending, not clawbacks)
     */
    public function handle(PointsJournalType $type, string $idempotencyKey, array $lines, ?string $sourceType = null, ?int $sourceId = null, ?string $description = null, array $noOverdrawUsers = []): PointsJournal
    {
        $existing = self::existing($type, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $merged = [];
        foreach ($lines as [$code, $owner, $amount]) {
            $key = "{$code->value}|{$owner}";
            $merged[$key] = ['code' => $code, 'owner' => $owner, 'amount' => ($merged[$key]['amount'] ?? 0) + $amount];
        }
        $merged = array_filter($merged, static fn (array $l): bool => $l['amount'] !== 0);

        if ($merged === [] || array_sum(array_column($merged, 'amount')) !== 0) {
            throw new LedgerException('Points journal is empty or does not sum to zero.');
        }

        try {
            return DB::transaction(function () use ($type, $idempotencyKey, $merged, $sourceType, $sourceId, $description, $noOverdrawUsers): PointsJournal {
                $now = now();
                $ids = [];
                foreach ($merged as $key => $line) {
                    $ids[$key] = Points::accountId($line['code'], $line['owner']);
                }
                $sorted = array_values(array_unique($ids));
                sort($sorted);

                DB::table('points_balances')->insertOrIgnore(array_map(static fn (int $id): array => ['account_id' => $id, 'balance' => 0, 'updated_at' => $now], $sorted));
                DB::table('points_balances')->whereIn('account_id', $sorted)->orderBy('account_id')->lockForUpdate()->get();

                $journal = new PointsJournal;
                $journal->forceFill([
                    'type' => $type,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'description' => $description === null ? null : mb_substr($description, 0, 255),
                    'idempotency_key' => $idempotencyKey,
                    'posted_at' => $now,
                ])->save();

                $rows = [];
                foreach ($merged as $key => $line) {
                    $rows[] = ['journal_id' => $journal->id, 'account_id' => $ids[$key], 'amount' => $line['amount'], 'created_at' => $now];
                    DB::table('points_balances')->where('account_id', $ids[$key])->increment('balance', $line['amount'], ['updated_at' => $now]);
                }
                DB::table('points_postings')->insert($rows);

                foreach ($noOverdrawUsers as $userId) {
                    if (Points::balance($userId) < 0) {
                        throw new BalanceOverdrawn("Posting would overdraw the points of user {$userId}.");
                    }
                }

                return $journal;
            });
        } catch (UniqueConstraintViolationException $e) {
            return self::existing($type, $idempotencyKey) ?? throw $e;
        }
    }

    private static function existing(PointsJournalType $type, string $key): ?PointsJournal
    {
        $journal = PointsJournal::query()->where('idempotency_key', $key)->first();

        if ($journal !== null && $journal->type !== $type) {
            throw new LedgerException("Idempotency key {$key} was used by a {$journal->type->value} points journal.");
        }

        return $journal;
    }
}
