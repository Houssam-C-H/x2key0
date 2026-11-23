<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;
use App\Domains\Points\Enums\PointsAccountCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statements read straight from the ledgers (spec 7.3, M9 "balances UI = ledger"): the Credits Balance log,
 * the Points Record, and the buyer's Funding Details (credits movements + card payments and refunds).
 * Amounts are signed from the user's point of view: positive = money/points in, negative = out.
 */
final class Statements
{
    /**
     * Credits Balance log with a running balance (spec 1.7: Date · Description · Currency · Amount · Balance).
     *
     * @return list<array{journalType: string, description: string|null, currency: string, amount: int, balance: int, at: string, order: string|null}>
     */
    public function credits(User $user, string $currency, Carbon $from, Carbon $to): array
    {
        $account = self::accountId(AccountRef::user(AccountCode::BuyerCredits, $user->id, $currency));
        if ($account === null) {
            return [];
        }

        $opening = self::int(DB::table('ledger_postings')->where('account_id', $account)->where('created_at', '<', $from)->sum('amount'));
        $rows = DB::table('ledger_postings')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_postings.account_id', $account)
            ->whereBetween('ledger_postings.created_at', [$from, $to])
            ->orderBy('ledger_postings.id')
            ->get(['ledger_postings.amount', 'ledger_postings.created_at', 'ledger_journals.type', 'ledger_journals.description', 'ledger_journals.source_type', 'ledger_journals.source_id']);

        $orders = self::orderNumbers($rows->where('source_type', 'order')->pluck('source_id')->all());
        $balance = -$opening;
        $out = [];
        foreach ($rows as $row) {
            $amount = -self::int($row->amount); // liability: credit (−) is money in for the user
            $balance += $amount;
            $out[] = [
                'journalType' => self::str($row->type),
                'description' => is_string($row->description) ? $row->description : null,
                'currency' => $currency,
                'amount' => $amount,
                'balance' => $balance,
                'at' => Carbon::parse(self::str($row->created_at))->toIso8601String(),
                'order' => $row->source_type === 'order' ? ($orders[self::int($row->source_id)] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, description: string|null, amount: int, at: string, order: string|null}>
     */
    public function points(User $user, Carbon $from, Carbon $to): array
    {
        $account = DB::table('points_accounts')->where('code', PointsAccountCode::User->value)->where('owner_user_id', $user->id)->value('id');
        if (! is_numeric($account)) {
            return [];
        }

        $rows = DB::table('points_postings')
            ->join('points_journals', 'points_journals.id', '=', 'points_postings.journal_id')
            ->where('points_postings.account_id', (int) $account)
            ->whereBetween('points_postings.created_at', [$from, $to])
            ->orderByDesc('points_postings.id')
            ->get(['points_postings.amount', 'points_postings.created_at', 'points_journals.type', 'points_journals.description', 'points_journals.source_type', 'points_journals.source_id']);

        $orders = self::orderNumbers($rows->where('source_type', 'order')->pluck('source_id')->all());

        return array_values(array_map(static fn (object $r): array => [
            'type' => self::str($r->type),
            'description' => is_string($r->description) ? $r->description : null,
            'amount' => self::int($r->amount),
            'at' => Carbon::parse(self::str($r->created_at))->toIso8601String(),
            'order' => $r->source_type === 'order' ? ($orders[self::int($r->source_id)] ?? null) : null,
        ], $rows->all()));
    }

    /**
     * Funds in/out for the buyer: credits movements, card payments (out) and card refunds (in).
     *
     * @return list<array{type: string, order: string|null, amount: int, currency: string, at: string}>
     */
    public function funding(User $user, string $currency, Carbon $from, Carbon $to): array
    {
        $rows = [];

        foreach ($this->credits($user, $currency, $from, $to) as $c) {
            $rows[] = ['type' => 'credits.'.strtolower($c['journalType']), 'order' => $c['order'], 'amount' => $c['amount'], 'currency' => $currency, 'at' => $c['at']];
        }

        $orderIds = Order::query()->where('buyer_id', $user->id)->pluck('number', 'id');
        if ($orderIds->isNotEmpty()) {
            foreach (Payment::query()->whereIn('order_id', $orderIds->keys())->where('provider', '!=', Payment::CREDITS)->where('status', PaymentStatus::Captured)->whereBetween('captured_at', [$from, $to])->get() as $p) {
                $rows[] = ['type' => 'card.payment', 'order' => self::name($orderIds[$p->order_id] ?? null), 'amount' => -$p->amount, 'currency' => $p->currency, 'at' => ($p->captured_at ?? $p->created_at)->toIso8601String()];
            }
            foreach (Refund::query()->whereIn('order_id', $orderIds->keys())->where('destination', Refund::ORIGINAL)->whereBetween('created_at', [$from, $to])->get() as $r) {
                $rows[] = ['type' => 'card.refund', 'order' => self::name($orderIds[$r->order_id] ?? null), 'amount' => $r->amount, 'currency' => $r->currency, 'at' => $r->created_at->toIso8601String()];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $rows;
    }

    /**
     * Months (YYYY-MM) with any credits, points or card activity, newest first, always including the current one.
     *
     * @return list<string>
     */
    public function months(User $user): array
    {
        $months = [now()->format('Y-m')];

        foreach ([
            DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->where('orders.buyer_id', $user->id)->pluck('payments.created_at'),
            DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
                ->where('ledger_accounts.code', AccountCode::BuyerCredits->value)->where('ledger_accounts.owner_id', $user->id)->pluck('ledger_postings.created_at'),
            DB::table('points_postings')->join('points_accounts', 'points_accounts.id', '=', 'points_postings.account_id')
                ->where('points_accounts.owner_user_id', $user->id)->pluck('points_postings.created_at'),
        ] as $dates) {
            foreach ($dates as $date) {
                $months[] = Carbon::parse(self::str($date))->format('Y-m');
            }
        }

        $months = array_values(array_unique($months));
        rsort($months);

        return $months;
    }

    /**
     * Seller balances shown in the Seller Center: pending (held), available, reserve — what the platform owes.
     *
     * @return array{pending: int, available: int, reserve: int, frozen: int, deposit: int, in_transit: int, currency: string}
     */
    public function sellerBalances(User $user, string $currency): array
    {
        return [
            'pending' => Accounts::owed(AccountRef::user(AccountCode::SellerPending, $user->id, $currency)),
            'available' => Accounts::owed(AccountRef::user(AccountCode::SellerAvailable, $user->id, $currency)),
            'reserve' => Accounts::owed(AccountRef::user(AccountCode::SellerReserve, $user->id, $currency)),
            'frozen' => Accounts::owed(AccountRef::user(AccountCode::SellerFrozen, $user->id, $currency)),
            'deposit' => Accounts::owed(AccountRef::user(AccountCode::SellerDeposit, $user->id, $currency)),
            'in_transit' => Accounts::owed(AccountRef::user(AccountCode::PayoutInTransit, $user->id, $currency)),
            'currency' => $currency,
        ];
    }

    private static function accountId(AccountRef $ref): ?int
    {
        $id = DB::table('ledger_accounts')->where(['code' => $ref->code, 'owner_type' => $ref->ownerType, 'owner_id' => $ref->ownerId, 'currency' => $ref->currency])->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<int, string>
     */
    private static function orderNumbers(array $ids): array
    {
        $out = [];
        foreach (Order::query()->whereIn('id', $ids)->get(['id', 'number']) as $order) {
            $out[$order->id] = $order->number;
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
