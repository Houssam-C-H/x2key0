<?php

declare(strict_types=1);

namespace App\Domains\Points\Support;

use App\Domains\Points\Enums\PointsAccountCode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Points accounts and balances (X2K Score). */
final class Points
{
    public static function accountId(PointsAccountCode $code, int $ownerUserId = 0): int
    {
        if (($code === PointsAccountCode::User) !== ($ownerUserId > 0)) {
            throw new RuntimeException('USER points accounts need an owner; platform accounts must not have one.');
        }

        $where = ['code' => $code->value, 'owner_user_id' => $ownerUserId];
        $id = DB::table('points_accounts')->where($where)->value('id');

        if ($id === null) {
            DB::table('points_accounts')->insertOrIgnore([...$where, 'created_at' => now()]);
            $id = DB::table('points_accounts')->where($where)->value('id');
        }

        return is_numeric($id) ? (int) $id : throw new RuntimeException('Points account could not be opened.');
    }

    /** The user's points (Score). */
    public static function balance(int $userId): int
    {
        $balance = DB::table('points_accounts')
            ->join('points_balances', 'points_balances.account_id', '=', 'points_accounts.id')
            ->where('points_accounts.code', PointsAccountCode::User->value)
            ->where('points_accounts.owner_user_id', $userId)
            ->value('points_balances.balance');

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * The user's latest points movements, newest first.
     *
     * @return list<array{at: string, type: string, points: int, description: string|null}>
     */
    public static function history(int $userId, int $limit = 20): array
    {
        return array_values(DB::table('points_postings')
            ->join('points_accounts', 'points_accounts.id', '=', 'points_postings.account_id')
            ->join('points_journals', 'points_journals.id', '=', 'points_postings.journal_id')
            ->where('points_accounts.code', PointsAccountCode::User->value)
            ->where('points_accounts.owner_user_id', $userId)
            ->orderByDesc('points_postings.id')
            ->limit($limit)
            ->get(['points_journals.posted_at', 'points_journals.type', 'points_postings.amount', 'points_journals.description'])
            ->map(fn (object $row): array => [
                'at' => is_string($row->posted_at ?? null) ? substr($row->posted_at, 0, 16) : '',
                'type' => is_string($row->type ?? null) ? str_replace('_', ' ', $row->type) : '',
                'points' => is_numeric($row->amount ?? null) ? (int) $row->amount : 0,
                'description' => is_string($row->description ?? null) ? $row->description : null,
            ])->all());
    }
}
