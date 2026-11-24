<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Delivery\Actions\RevealOrderSecrets;
use App\Domains\Delivery\Models\Delivery;
use App\Domains\Delivery\Models\DeliveryEvidence;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Support\DisputeRules;
use App\Domains\Disputes\Support\OpenCases;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Orders\Support\OrderPresenter;
use App\Domains\Orders\Support\OrderRules;
use App\Domains\Payments\Actions\SyncPendingPayments;
use App\Domains\Payments\Models\Payment;
use App\Domains\Reviews\Actions\OrderComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buy › Purchase Orders and order detail (spec 1.6 / 6.1 / 8.3): status, timeline, deliveries with an
 * audited reveal, cancellation after a missed deadline, cancel remainder. Confirmation, chat and disputes
 * join with M9–M11.
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
        $order = $this->own($request, $publicId);

        // Back from Stripe's hosted page: ask Stripe for the session now instead of waiting for the webhook or the
        // sync job. The state comes from Stripe's API, never from the redirect itself (ADR-017); throttled per payment.
        if ($request->query('stripe') === 'return' && $order->status === OrderStatus::PendingPayment) {
            $payment = $order->payments->first(fn (Payment $p): bool => $p->provider === 'stripe' && ! $p->status->isFinal());
            if ($payment !== null && Cache::add("stripe-return:{$payment->id}", 1, 5)) {
                app(SyncPendingPayments::class)->syncOne($payment);
            }
        }

        return Inertia::render('Buy/Orders/Show', [
            'order' => OrderPresenter::detail($order, app()->getLocale(), "/buy/orders/{$order->public_id}/evidence"),
            'can' => [
                'cancel' => OrderRules::buyerCanCancel($order),
                'cancelRemainder' => OrderRules::buyerCanCancelRemainder($order),
                'reveal' => OrderRules::buyerCanReveal($order),
                'confirm' => $order->status === OrderStatus::Delivered && ! OpenCases::blocks($order->id),
                'comment' => app(OrderComments::class)->canComment($order),
            ],
            'dispute' => app(DisputeRules::class)->forOrder($order, DisputeRole::Buyer),
        ]);
    }

    /** Decrypts what was delivered (JSON only, never cached; every secret shown is logged). */
    public function reveal(Request $request, RevealOrderSecrets $reveal, string $publicId): JsonResponse
    {
        $order = $this->own($request, $publicId);
        abort_unless(OrderRules::buyerCanReveal($order), 404);

        $secrets = $reveal->handle($order, self::user($request), $request->ip(), $request->userAgent());

        return response()->json(['secrets' => $secrets])->header('Cache-Control', 'no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    public function confirm(Request $request, OrderStateMachine $machine, string $publicId): RedirectResponse
    {
        $order = $this->own($request, $publicId);

        if ($order->status !== OrderStatus::Delivered) {
            return back()->withErrors(['order' => __('order.error.cannot_confirm')]);
        }

        try {
            $machine->complete($order, ActorType::Buyer, self::user($request));
        } catch (CheckoutException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('success', __('order.confirmed'));
    }

    public function cancel(Request $request, OrderStateMachine $machine, string $publicId): RedirectResponse
    {
        $order = $this->own($request, $publicId);
        if (! OrderRules::buyerCanCancel($order)) {
            return back()->withErrors(['order' => __('order.error.cannot_cancel')]);
        }

        try {
            $machine->cancel($order, 'buyer_cancel_sla', ActorType::Buyer, self::user($request), $request->boolean('to_credits'));
        } catch (CheckoutException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('success', __('order.cancelled_refunded'));
    }

    public function cancelRemainder(Request $request, OrderStateMachine $machine, string $publicId): RedirectResponse
    {
        $order = $this->own($request, $publicId);
        if (! OrderRules::buyerCanCancelRemainder($order)) {
            return back()->withErrors(['order' => __('order.error.cannot_cancel')]);
        }

        try {
            $machine->cancelRemainder($order, ActorType::Buyer, self::user($request), $request->boolean('to_credits'));
        } catch (CheckoutException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('success', __('order.remainder_refunded'));
    }

    public function evidence(Request $request, string $publicId, int $evidence): StreamedResponse
    {
        $order = $this->own($request, $publicId);

        return self::streamEvidence($order, $evidence);
    }

    /** Streams an evidence file of the order from the private disk (buyer or seller of the order only). */
    public static function streamEvidence(Order $order, int $evidenceId): StreamedResponse
    {
        $file = DeliveryEvidence::query()->whereKey($evidenceId)
            ->whereIn('delivery_id', Delivery::query()->select('id')->where('order_item_id', $order->item->id))
            ->first();
        abort_if($file === null, 404);

        return Storage::disk('local')->response($file->file_path, null, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }

    private function own(Request $request, string $publicId): Order
    {
        $order = Order::query()->where('public_id', $publicId)->where('buyer_id', self::user($request)->id)->with(OrderPresenter::relations())->first();
        abort_if($order === null, 404);

        return $order;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
