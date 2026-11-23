<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerStats;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Settlement of a completed order (spec 6.1 → `completed`, 7.2 ORDER_SETTLEMENT, 7.5 timers).
 *
 * ORDER_SETTLEMENT: ORDER_ESCROW + base · SELLER_PENDING(seller) − net · REV_COMMISSION − commission, where
 * base = unit price × units kept (delivered, not refunded) and commission = seller level rate on base.
 *
 * The seller's net is then held (rows in `holds`) and released by `holds:release`:
 * - hold days = max(category default, seller level hold days) + new-seller extra while the store has fewer
 *   than `new_seller_order_threshold` completed orders;
 * - accounts also wait for the recovery-protection window to end;
 * - insured offers (insurance_days > 0) release in `insurance_installments` equal parts spread over the
 *   insurance period, never before the hold ends (spec 1.8 #10).
 * Idempotent per order (journal key + unique hold rows).
 */
final class SettleOrder
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Settings $settings,
    ) {}

    public function handle(Order $order): void
    {
        $order->loadMissing(['item.offer.gameCategory.type', 'seller.level', 'seller.stats']);
        $item = $order->item;
        $currency = $order->currency;

        $base = Amount::ofMinor($item->unit_price_amount, $currency)->multipliedBy($item->quantity - $item->refunded_qty);
        $seller = $order->seller;
        $commissionBps = $seller->level->commission_bps ?? $order->commission_bps;
        $commission = Amount::bps($base, $commissionBps);
        $net = Amount::toMinor($base->minus($commission));

        $order->forceFill([
            'commission_bps' => $commissionBps,
            'commission_amount' => Amount::toMinor($commission),
            'seller_net_amount' => $net,
        ]);

        $this->post->handle(new JournalDraft(
            type: JournalType::OrderSettlement,
            idempotencyKey: "ORDER_SETTLEMENT:order:{$order->id}",
            lines: [
                [AccountRef::platform(AccountCode::OrderEscrow, $currency), Amount::toMinor($base)],
                [AccountRef::user(AccountCode::SellerPending, $seller->user_id, $currency), -$net],
                [AccountRef::platform(AccountCode::RevCommission, $currency), -Amount::toMinor($commission)],
            ],
            sourceType: 'order',
            sourceId: $order->id,
            description: "Order {$order->number} completed",
        ));

        $this->holds($order, $net, $seller->level->reserve_bps ?? 0);

        $stats = SellerStats::query()->firstOrCreate(['seller_profile_id' => $order->seller_profile_id]);
        $stats->forceFill([
            'orders_completed' => $stats->orders_completed + 1,
            'sales_30d_amount' => $stats->sales_30d_amount + Amount::toMinor($base),
        ])->save();
    }

    private function holds(Order $order, int $net, int $reserveBps): void
    {
        if ($net <= 0) {
            return;
        }

        $item = $order->item;
        $offer = $item->offer;
        $now = now();
        $seller = $order->seller;

        $days = max(
            $this->settings->int('settlement_hold_days.'.$offer->gameCategory->type->code->value),
            $seller->level->hold_days ?? 0,
        );
        if (($seller->stats->orders_completed ?? 0) < $this->settings->int('new_seller_order_threshold')) {
            $days += $this->settings->int('new_seller_extra_hold_days');
        }
        $release = $now->copy()->addDays($days);

        if ($offer->delivery_method === DeliveryMethod::AccountTransfer) {
            $protection = is_int($p = $item->snapshot['account_recovery_protection_days'] ?? null) ? $p : $this->settings->int('account_recovery_protection_days');
            $release = $release->max($now->copy()->addDays($protection));
        }

        $insuranceDays = is_int($d = $item->snapshot['insurance_days'] ?? null) ? $d : 0;
        $parts = max(1, $this->settings->int('insurance_installments'));

        if ($insuranceDays > 0) {
            foreach (Amount::split(Amount::ofMinor($net, $order->currency), $parts) as $i => $part) {
                $this->hold($order, Hold::INSURANCE, $i + 1, Amount::toMinor($part), $reserveBps, $release->max($now->copy()->addDays((int) ceil($insuranceDays * ($i + 1) / $parts))));
            }
        } else {
            $this->hold($order, Hold::SETTLEMENT, 1, $net, $reserveBps, $release);
        }

        $last = Hold::query()->where('order_id', $order->id)->max('release_at');
        $order->forceFill(['settle_after' => is_string($last) ? Carbon::parse($last) : $release]);
    }

    private function hold(Order $order, string $kind, int $seq, int $amount, int $reserveBps, Carbon $release): void
    {
        if ($amount <= 0 || Hold::query()->where(['order_id' => $order->id, 'kind' => $kind, 'seq' => $seq])->whereNull('source_hold_id')->exists()) {
            return;
        }

        (new Hold)->forceFill([
            'owner_type' => 'user',
            'owner_id' => $order->seller->user_id,
            'order_id' => $order->id,
            'kind' => $kind,
            'seq' => $seq,
            'amount' => $amount,
            'reserve_bps' => $reserveBps,
            'currency' => $order->currency,
            'release_at' => $release,
            'source_hold_id' => null,
        ])->save();
    }
}
