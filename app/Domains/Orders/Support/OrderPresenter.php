<?php

declare(strict_types=1);

namespace App\Domains\Orders\Support;

use App\Domains\Delivery\Models\Delivery;
use App\Domains\Delivery\Models\DeliveryEvidence;
use App\Domains\Delivery\Models\ServiceMilestone;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;

/**
 * Order detail props shared by the buyer page (Purchase Orders) and the seller page (Sold Orders).
 * Never includes secrets: delivered codes are fetched through the audited reveal endpoint.
 */
final class OrderPresenter
{
    /**
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['item.offer', 'item.deliveries.secrets', 'item.deliveries.evidence', 'item.deliveries.storageItems', 'item.milestones', 'seller', 'buyer', 'payments', 'history', 'refunds'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Order $order, string $locale, string $evidenceBase): array
    {
        $item = $order->item;
        $snapshot = $item->snapshot;
        $psp = $order->payments->first(fn (Payment $p): bool => $p->provider !== Payment::CREDITS);

        return [
            'publicId' => $order->public_id,
            'number' => $order->number,
            'status' => $order->status->value,
            'substatus' => $order->seller_substatus?->value,
            'currency' => $order->currency,
            'createdAt' => $order->created_at->toIso8601String(),
            'paymentDueAt' => $order->payment_due_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'deliveryDueAt' => $order->delivery_due_at?->toIso8601String(),
            'deliveredAt' => $order->delivered_at?->toIso8601String(),
            'confirmDueAt' => $order->confirm_due_at?->toIso8601String(),
            'acceptedAt' => $order->accepted_at?->toIso8601String(),
            'completedAt' => $order->completed_at?->toIso8601String(),
            'cancelledAt' => $order->cancelled_at?->toIso8601String(),
            'cancelReason' => $order->cancel_reason,
            'lines' => [
                'subtotal' => $order->subtotal_amount,
                'discountSeller' => $order->discount_seller_amount,
                'discountPlatform' => $order->discount_platform_amount,
                'buyerFee' => $order->buyer_fee_amount + $order->method_fee_amount,
                'addonFee' => $order->addon_fee_amount,
                'tax' => $order->tax_amount,
                'total' => $order->total_amount,
                'credits' => $order->paid_credits_amount,
                'psp' => $order->pspDue(),
                'refunded' => $order->refunded_credits_amount + $order->refunded_psp_amount,
                'sellerNet' => $order->seller_net_amount,
                'commission' => $order->commission_amount,
            ],
            'item' => [
                'title' => $snapshot['title'] ?? '',
                'gameCategory' => $snapshot['game_category'] ?? '',
                'deliveryMethod' => $snapshot['delivery_method'] ?? '',
                'auto' => ($snapshot['fulfillment'] ?? null) === 'auto',
                'deliveryMinutes' => $snapshot['delivery_time_minutes'] ?? null,
                'quantity' => $item->quantity,
                'delivered' => $item->delivered_qty,
                'refunded' => $item->refunded_qty,
                'remaining' => $item->remaining(),
                'unitPrice' => $item->unit_price_amount,
                'offerHref' => is_string($snapshot['offer_public_id'] ?? null) ? "/{$locale}/offer/{$snapshot['offer_public_id']}" : null,
                'snapshot' => [
                    'description' => is_array($snapshot['description'] ?? null) ? ($snapshot['description'][$locale] ?? $snapshot['description']['en'] ?? null) : null,
                    'insuranceDays' => $snapshot['insurance_days'] ?? 0,
                    'version' => $snapshot['offer_version'] ?? 1,
                ],
            ],
            'seller' => ['name' => $order->seller->store_name, 'href' => $order->seller->path($locale)],
            'payment' => $psp === null ? null : [
                'status' => $psp->status->value,
                'method' => $psp->method_code,
                'redirectUrl' => $order->status->value === 'pending_payment' && ! $psp->status->isFinal() ? $psp->redirect_url : null,
            ],
            'deliveries' => $item->deliveries->sortBy('id')->values()->map(static fn (Delivery $d): array => [
                'id' => $d->id,
                'quantity' => $d->quantity,
                'by' => $d->delivered_by_type,
                'note' => $d->note,
                'secrets' => $d->secrets->count() + $d->storageItems->count(),
                'evidence' => $d->evidence->map(static fn (DeliveryEvidence $e): array => ['id' => $e->id, 'url' => "{$evidenceBase}/{$e->id}", 'mime' => $e->mime])->values()->all(),
                'at' => $d->created_at->toIso8601String(),
            ])->all(),
            'milestones' => $item->milestones->sortBy('id')->values()->map(static fn (ServiceMilestone $m): array => [
                'title' => $m->title, 'percent' => $m->percent, 'note' => $m->note, 'at' => $m->created_at->toIso8601String(),
            ])->all(),
            'refunds' => $order->refunds->sortBy('id')->values()->map(static fn (Refund $r): array => [
                'amount' => $r->amount, 'destination' => $r->destination, 'status' => $r->status, 'at' => $r->created_at->toIso8601String(),
            ])->all(),
            'timeline' => $order->history->sortByDesc('id')->values()->map(static fn (OrderStatusHistory $h): array => [
                'to' => $h->to_status->value,
                'from' => $h->from_status?->value,
                'toSub' => $h->to_substatus?->value,
                'fromSub' => $h->from_substatus?->value,
                'units' => is_array($h->meta) && is_int($h->meta['units'] ?? null) ? $h->meta['units'] : null,
                'actor' => $h->actor_type->value,
                'reason' => $h->reason,
                'at' => $h->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
