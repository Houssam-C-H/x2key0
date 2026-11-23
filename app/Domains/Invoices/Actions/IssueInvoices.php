<?php

declare(strict_types=1);

namespace App\Domains\Invoices\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Invoices\Models\Invoice;
use App\Domains\Invoices\Models\InvoiceProfile;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invoices (Part 16), idempotent per source (unique type × source):
 *
 * - withdrawal voucher — one per paid withdrawal (spec 6.6), issued in the payment transaction;
 * - service invoice — one per seller and month for the platform's fees: commission of orders completed that month
 *   and payout fees of withdrawals paid that month (nothing to bill → no invoice).
 *
 * Numbers are `X2W-{year}-{000001}` (vouchers) and `X2S-{year}-{000001}` (service), sequential per prefix and year
 * from a locked counter row, so there are no gaps or duplicates. The buyer's Invoice info is copied at issue time.
 */
final class IssueInvoices
{
    public function voucher(WithdrawalRequest $w): Invoice
    {
        $existing = Invoice::query()->where(['type' => Invoice::VOUCHER, 'source_type' => 'withdrawal', 'source_id' => $w->id])->first();
        if ($existing !== null) {
            return $existing;
        }

        $w->loadMissing('user');

        return $this->issue(Invoice::VOUCHER, 'X2W', $w->user, 'withdrawal', $w->id, null, null, [
            ['label' => 'invoice.line.withdrawal', 'amount' => $w->amount],
            ['label' => 'invoice.line.payout_fee', 'amount' => -$w->fee_amount],
        ], $w->payout_amount, $w->currency, ['method' => $w->payout_label, 'provider_ref' => $w->provider_ref, 'withdrawal' => $w->number()]);
    }

    /** Issues last month's (or the given month's) service invoices for every seller; returns how many were new. */
    public function monthly(?Carbon $month = null): int
    {
        $start = ($month ?? now()->subMonthNoOverflow())->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $issued = 0;

        SellerProfile::query()->with('user')->orderBy('id')->chunkById(200, function ($sellers) use ($start, $end, &$issued): void {
            foreach ($sellers as $seller) {
                $issued += $this->service($seller, $start, $end) === null ? 0 : 1;
            }
        });

        return $issued;
    }

    public function service(SellerProfile $seller, Carbon $start, Carbon $end): ?Invoice
    {
        $sourceId = (int) $start->format('Ym');
        $existing = Invoice::query()->where(['type' => Invoice::SERVICE, 'user_id' => $seller->user_id, 'source_type' => 'month', 'source_id' => $sourceId])->first();
        if ($existing !== null) {
            return null;
        }

        $commission = (int) Order::query()->where('seller_profile_id', $seller->id)->where('status', OrderStatus::Completed->value)
            ->whereBetween('completed_at', [$start, $end])->sum('commission_amount');
        $orders = Order::query()->where('seller_profile_id', $seller->id)->where('status', OrderStatus::Completed->value)
            ->whereBetween('completed_at', [$start, $end])->count();
        $payoutFees = (int) WithdrawalRequest::query()->where('user_id', $seller->user_id)->where('status', WithdrawalStatus::Paid->value)
            ->whereBetween('paid_at', [$start, $end])->sum('fee_amount');

        $total = $commission + $payoutFees;
        if ($total <= 0) {
            return null;
        }

        return $this->issue(Invoice::SERVICE, 'X2S', $seller->user, 'month', $sourceId, $start, $end, [
            ['label' => 'invoice.line.commission', 'amount' => $commission, 'count' => $orders],
            ['label' => 'invoice.line.payout_fees', 'amount' => $payoutFees],
        ], $total, 'USD', ['store' => $seller->store_name]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function issue(string $type, string $prefix, User $user, string $sourceType, ?int $sourceId, ?Carbon $start, ?Carbon $end, array $lines, int $total, string $currency, array $extra): Invoice
    {
        return DB::transaction(function () use ($type, $prefix, $user, $sourceType, $sourceId, $start, $end, $lines, $total, $currency, $extra): Invoice {
            $now = now();
            $profile = InvoiceProfile::query()->where('user_id', $user->id)->first();

            $invoice = new Invoice;
            $invoice->forceFill([
                'number' => self::nextNumber($prefix, (int) $now->format('Y')),
                'type' => $type,
                'user_id' => $user->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'period_start' => $start?->toDateString(),
                'period_end' => $end?->toDateString(),
                'lines' => $lines,
                'billed_to' => [
                    'name' => $profile->legal_name ?? $user->name,
                    'tax_id' => $profile?->tax_id_encrypted,
                    'address' => $profile->address ?? null,
                    'email' => $profile->email ?? $user->email,
                    'username' => $user->username,
                    ...$extra,
                ],
                'subtotal' => $total,
                'tax' => 0,
                'total' => $total,
                'currency' => $currency,
                'issued_at' => $now,
            ])->save();

            return $invoice;
        });
    }

    private static function nextNumber(string $prefix, int $year): string
    {
        DB::table('invoice_sequences')->insertOrIgnore(['prefix' => $prefix, 'year' => $year, 'last_number' => 0]);
        DB::table('invoice_sequences')->where(['prefix' => $prefix, 'year' => $year])->lockForUpdate()->first();
        DB::table('invoice_sequences')->where(['prefix' => $prefix, 'year' => $year])->increment('last_number');
        $next = DB::table('invoice_sequences')->where(['prefix' => $prefix, 'year' => $year])->value('last_number');

        return sprintf('%s-%d-%06d', $prefix, $year, is_numeric($next) ? (int) $next : 0);
    }
}
