<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Delivery\Models\Delivery;
use App\Domains\Delivery\Models\SecretReveal;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Models\LedgerPosting;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Payments\Models\Chargeback;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;
use App\Domains\Risk\Models\RiskFlag;
use Illuminate\Database\Eloquent\Builder;

/**
 * One order on one page (docs/ADMIN_PLAN.md item 4): money lines, status history, payments, refunds, deliveries
 * (counts and methods, never the delivered secrets or buyer inputs), secret reveals, chat summary, disputes,
 * chargebacks, holds, ledger journals, risk result and staff notes.
 */
final class OrderOverview
{
    public function __construct(private readonly StaffNotes $notes) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Order $order): array
    {
        $order->loadMissing(['buyer', 'seller', 'item']);
        $c = $order->currency;
        $item = $order->item;
        $paymentIds = Payment::query()->where('order_id', $order->id)->pluck('id')->all();
        $holdIds = Hold::query()->where('order_id', $order->id)->pluck('id')->all();
        $chargebackIds = Chargeback::query()->where('order_id', $order->id)->pluck('id')->all();
        $conversation = Conversation::query()->where('order_id', $order->id)->first();

        return [
            'summary' => [
                'Number' => $order->number,
                'Status' => Format::enum($order->status).($order->seller_substatus !== null ? ' · '.Format::enum($order->seller_substatus) : ''),
                'Item' => (is_string($item->snapshot['title'] ?? null) ? $item->snapshot['title'] : '—').' × '.$item->quantity,
                'Delivered / refunded units' => $item->delivered_qty.' / '.$item->refunded_qty,
                'Created' => Format::date($order->created_at),
                'Paid' => Format::date($order->paid_at),
                'Delivered' => Format::date($order->delivered_at),
                'Confirm due' => Format::date($order->confirm_due_at),
                'Completed' => Format::date($order->completed_at),
                'Cancelled' => $order->cancelled_at === null ? '—' : Format::date($order->cancelled_at).' ('.($order->cancel_reason ?? '').')',
                'Buyer country / IP' => ($order->buyer_country ?? '—').' · '.($order->ip ?? '—'),
            ],
            'buyer' => ['id' => $order->buyer_id, 'name' => $order->buyer->username],
            'store' => ['id' => $order->seller_profile_id, 'name' => $order->seller->store_name],
            'money' => [
                'Subtotal' => Format::money($order->subtotal_amount, $c),
                'Store discount' => Format::money(-$order->discount_seller_amount, $c),
                'Platform discount' => Format::money(-$order->discount_platform_amount, $c),
                'Buyer fee' => Format::money($order->buyer_fee_amount, $c),
                'Add-ons / method fee / tax' => Format::money($order->addon_fee_amount, $c).' / '.Format::money($order->method_fee_amount, $c).' / '.Format::money($order->tax_amount, $c),
                'Total' => Format::money($order->total_amount, $c),
                'Paid by card / credits' => Format::money($order->paid_psp_amount, $c).' / '.Format::money($order->paid_credits_amount, $c),
                'Refunded (card / credits)' => Format::money($order->refunded_psp_amount, $c).' / '.Format::money($order->refunded_credits_amount, $c),
                'Commission' => Format::money($order->commission_amount, $c).' ('.number_format($order->commission_bps / 100, 2).' %)',
                'Seller net' => Format::money($order->seller_net_amount, $c),
            ],
            'risk' => [
                'score' => $order->risk_score,
                'decision' => Format::enum($order->risk_decision),
                'flags' => RiskFlag::query()->where('subject_type', 'order')->where('subject_id', $order->id)->latest('id')->get()
                    ->map(fn (RiskFlag $f): array => ['rule' => $f->rule, 'score' => $f->score, 'status' => $f->status, 'at' => Format::date($f->created_at)])->all(),
            ],
            'history' => OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OrderStatusHistory $h): array => [
                    'at' => Format::date($h->created_at),
                    'change' => Format::enum($h->from_status).' → '.Format::enum($h->to_status),
                    'actor' => $h->actor_type->value.($h->actor_id !== null ? ' #'.$h->actor_id : ''),
                    'reason' => (string) $h->reason,
                ])->all(),
            'payments' => Payment::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (Payment $p): array => [
                    'id' => $p->public_id,
                    'provider' => $p->provider.' · '.$p->method_code,
                    'amount' => Format::money($p->amount, $p->currency),
                    'status' => Format::enum($p->status),
                    'card' => $p->card_last4 === null ? '—' : '•••• '.$p->card_last4.' ('.($p->card_country ?? '?').')'.($p->three_ds ? ' 3DS' : ''),
                    'failure' => $p->failure_reason,
                    'at' => Format::date($p->created_at),
                ])->all(),
            'refunds' => Refund::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (Refund $r): array => [
                    'amount' => Format::money($r->amount, $r->currency),
                    'destination' => Format::enum($r->destination),
                    'status' => $r->status,
                    'reason' => $r->reason,
                    'by' => $r->initiated_by_type.($r->initiated_by_id !== null ? ' #'.$r->initiated_by_id : ''),
                    'at' => Format::date($r->created_at),
                ])->all(),
            'deliveries' => Delivery::query()->where('order_item_id', $item->id)->orderBy('id')->get()
                ->map(fn (Delivery $d): array => [
                    'qty' => $d->quantity,
                    'method' => Format::enum($d->method),
                    'by' => $d->delivered_by_type.($d->delivered_by_id !== null ? ' #'.$d->delivered_by_id : ''),
                    'at' => Format::date($d->created_at),
                ])->all(),
            'reveals' => SecretReveal::query()->where('order_item_id', $item->id)->count(),
            'chat' => $conversation === null ? null : [
                'publicId' => $conversation->public_id,
                'messages' => Message::query()->where('conversation_id', $conversation->id)->count(),
                'flagged' => Message::query()->where('conversation_id', $conversation->id)->whereNotNull('flags')->count(),
                'last' => Format::date($conversation->last_message_at),
            ],
            'disputes' => Dispute::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (Dispute $d): array => [
                    'id' => $d->public_id,
                    'reason' => Format::enum($d->reason),
                    'status' => Format::enum($d->status),
                    'requested' => Format::enum($d->requested_resolution),
                    'at' => Format::date($d->created_at),
                ])->all(),
            'chargebacks' => Chargeback::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (Chargeback $cb): array => [
                    'case' => $cb->provider_case_id,
                    'amount' => Format::money($cb->amount, $cb->currency),
                    'status' => $cb->status,
                    'at' => Format::date($cb->opened_at),
                ])->all(),
            'holds' => Hold::query()->where('order_id', $order->id)->orderBy('seq')->get()
                ->map(fn (Hold $h): array => [
                    'kind' => $h->kind.' #'.$h->seq,
                    'amount' => Format::money($h->amount, $h->currency),
                    'release' => Format::date($h->release_at),
                    'state' => $h->released_at !== null ? 'released '.Format::date($h->released_at) : ($h->frozen_by !== null ? 'frozen ('.$h->frozen_by.')' : 'held'),
                ])->all(),
            'journals' => LedgerJournal::query()->with('postings.account')
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $w) => $w->where('source_type', 'order')->where('source_id', $order->id))
                    ->orWhere(fn (Builder $w) => $w->where('source_type', 'payment')->whereIn('source_id', $paymentIds))
                    ->orWhere(fn (Builder $w) => $w->where('source_type', 'hold')->whereIn('source_id', $holdIds))
                    ->orWhere(fn (Builder $w) => $w->where('source_type', 'chargeback')->whereIn('source_id', $chargebackIds)))
                ->orderBy('id')->get()
                ->map(fn (LedgerJournal $j): array => [
                    'type' => $j->type->value,
                    'at' => Format::date($j->posted_at),
                    'lines' => $j->postings->map(fn (LedgerPosting $p): string => $p->account->code
                        .($p->account->owner_type === 'user' ? ' (user '.$p->account->owner_id.')' : '')
                        .' '.($p->amount > 0 ? '+' : '−').Format::money(abs($p->amount), $p->currency))->all(),
                ])->all(),
            'notes' => $this->notes->for('order', $order->id)->map(fn ($n): array => [
                'author' => $n->author->username,
                'at' => Format::date($n->created_at),
                'body' => $n->body,
            ])->all(),
        ];
    }
}
