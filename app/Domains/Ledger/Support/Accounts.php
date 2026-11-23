<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Support;

use App\Domains\Ledger\Data\AccountRef;
use Illuminate\Support\Facades\DB;

/**
 * Finds or opens ledger accounts and reads balances. Accounts are opened with INSERT … ON CONFLICT DO
 * NOTHING semantics (insertOrIgnore), so two transactions opening the same account never fail.
 */
final class Accounts
{
    public static function id(AccountRef $ref): int
    {
        $where = ['code' => $ref->code, 'owner_type' => $ref->ownerType, 'owner_id' => $ref->ownerId, 'currency' => $ref->currency];
        $id = DB::table('ledger_accounts')->where($where)->value('id');

        if ($id === null) {
            DB::table('ledger_accounts')->insertOrIgnore([...$where, 'type' => $ref->type->value, 'created_at' => now()]);
            $id = DB::table('ledger_accounts')->where($where)->value('id');
        }

        return is_numeric($id) ? (int) $id : throw new \RuntimeException('Ledger account could not be opened.');
    }

    /** Signed balance (debit +, credit −); 0 for an account never used. */
    public static function balance(AccountRef $ref): int
    {
        $balance = DB::table('ledger_accounts')
            ->join('ledger_balances', 'ledger_balances.account_id', '=', 'ledger_accounts.id')
            ->where(['code' => $ref->code, 'owner_type' => $ref->ownerType, 'owner_id' => $ref->ownerId, 'currency' => $ref->currency])
            ->value('ledger_balances.balance');

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /** What the platform owes the user on a liability account (spec 7.1: shown as −balance). */
    public static function owed(AccountRef $ref): int
    {
        return -self::balance($ref);
    }
}
