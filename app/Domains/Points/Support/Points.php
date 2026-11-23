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
}
