<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buy › Purchase Orders and order detail (spec 1.6 / 6.1 buyer tabs). M6 shows payment and status; delivery,
 * reveal, confirmation, chat and disputes join the detail page with M8–M11.
 */
final class PurchaseOrderController
{
    public const array TABS = ['all', 'pending_payment', 'pending_delivery', 'to_confirm', 'completed', 'cancelled'];

    public function index(Request $request): Response
    {
        $buyer = self::user($request);
        $tab = in_array($t = $request->query('tab'), self::TABS, true) ? $t : 'all';

        $base = fn (): Builder => Order::query()->where('buyer_id', $buyer->id);
        $counts = ['all' => $base()->count()];
        foreach (array_slice(self::TABS, 1) as $name) {
            $counts[$name] = $base()->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::forBuyerTab($name)))->count();
        }

        $page = $base()
            ->when($tab !== 'all', fn (Builder $q) => $q->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::forBuyerTab($tab))))
            ->with(['item', 'seller'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Buy/Orders/Index', [
            'orders' => [
                'items' => array_map(static fn (Order $o): array => [
                    'publicId' => $o->public_id,
                    'number' => $o->number,
                    'title' => is_string($o->item->snapshot['title'] ?? null) ? $o->item->snapshot['title'] : '',
                    'seller' => $o->seller->store_name,
                    'quantity' => $o->item->quantity,
                    'total' => $o->total_amount,
                    'currency' => $o->currency,
                    'status' => $o->status->value,
                    'createdAt' => $o->created_at->toIso8601String(),
                ], $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
            'counts' => $counts,
            'tab' => $tab,
            'tabs' => self::TABS,
        ]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $buyer = self::user($request);
        $order = Order::query()->where('public_id', $publicId)->where('buyer_id', $buyer->id)->with(['item', 'seller', 'payments', 'history'])->first();
        abort_if($order === null, 404);

        $snapshot = $order->item->snapshot;
        $psp = $order->payments->first(fn (Payment $p): bool => $p->provider !== Payment::CREDITS);
        $locale = app()->getLocale();

        return Inertia::render('Buy/Orders/Show', [
            'order' => [
                'publicId' => $order->public_id,
                'number' => $order->number,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'createdAt' => $order->created_at->toIso8601String(),
                'paymentDueAt' => $order->status === OrderStatus::PendingPayment ? $order->payment_due_at?->toIso8601String() : null,
                'paidAt' => $order->paid_at?->toIso8601String(),
                'deliveryDueAt' => $order->delivery_due_at?->toIso8601String(),
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
                ],
                'item' => [
                    'title' => $snapshot['title'] ?? '',
                    'gameCategory' => $snapshot['game_category'] ?? '',
                    'deliveryMethod' => $snapshot['delivery_method'] ?? '',
                    'deliveryMinutes' => $snapshot['delivery_time_minutes'] ?? null,
                    'quantity' => $order->item->quantity,
                    'unitPrice' => $order->item->unit_price_amount,
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
                    'redirectUrl' => $order->status === OrderStatus::PendingPayment && ! $psp->status->isFinal() ? $psp->redirect_url : null,
                ],
                'timeline' => $order->history->sortByDesc('id')->values()->map(static fn (OrderStatusHistory $h): array => [
                    'to' => $h->to_status->value,
                    'from' => $h->from_status?->value,
                    'actor' => $h->actor_type->value,
                    'reason' => $h->reason,
                    'at' => $h->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
