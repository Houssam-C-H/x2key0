<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\Game;
use App\Domains\Delivery\Actions\DeliverOrder;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Support\Statements;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Orders\Support\OrderPresenter;
use App\Domains\Orders\Support\OrderRules;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Contracts\SecretVault;
use App\Http\Controllers\Buy\PurchaseOrderController;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Money\Amount;
use App\Shared\Validation\NoContactInfo;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller Center › Sold Orders (spec 1.8 / 6.1): tabs with counts, filters, sub-status chips, internal remarks
 * and acquisition cost (seller-private), and the order page — buyer inputs after payment, accept / in trading,
 * delivery per method, milestones, cancel with refund.
 */
final class SoldOrderController
{
    use ResolvesSeller;

    /** Seller tabs (spec 6.1 mapping): Pending delivery = paid/delivering, Pending feedback = delivered, Delivered = completed. */
    public const array TABS = [
        'all' => [],
        'pending_delivery' => ['paid', 'delivering'],
        'pending_feedback' => ['delivered'],
        'delivered' => ['completed'],
        'cancelled' => ['cancelled', 'refunded'],
    ];

    public function index(Request $request): Response
    {
        $profile = $this->seller($request);
        $locale = app()->getLocale();
        $tab = array_key_exists($t = (string) $request->query('tab', 'all'), self::TABS) ? $t : 'all';
        $f = [
            'game' => $request->integer('game') ?: null,
            'type' => is_string($v = $request->query('type')) && CategoryTypeCode::tryFrom($v) !== null ? $v : null,
            'number' => is_string($v = $request->query('number')) ? mb_substr(trim($v), 0, 32) : '',
            'title' => is_string($v = $request->query('title')) ? mb_substr(trim($v), 0, 100) : '',
            'remark' => is_string($v = $request->query('remark')) ? mb_substr(trim($v), 0, 100) : '',
            'from' => is_string($v = $request->query('from')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null,
            'to' => is_string($v = $request->query('to')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null,
        ];
        $like = static fn (string $s): string => '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';

        // Paid orders only: an order the buyer never paid is not the seller's business.
        $base = fn (): Builder => Order::query()
            ->where('orders.seller_profile_id', $profile->id)
            ->whereNotNull('orders.paid_at')
            ->when($f['game'] !== null, fn (Builder $q) => $q->whereHas('item.offer.gameCategory', fn (Builder $g) => $g->where('game_id', $f['game'])))
            ->when($f['type'] !== null, fn (Builder $q) => $q->whereHas('item.offer.gameCategory.type', fn (Builder $g) => $g->where('code', $f['type'])))
            ->when($f['number'] !== '', fn (Builder $q) => $q->where('orders.number', 'like', $like($f['number'])))
            ->when($f['title'] !== '', fn (Builder $q) => $q->whereHas('item.offer', fn (Builder $o) => $o->where('title', 'like', $like($f['title']))))
            ->when($f['remark'] !== '', fn (Builder $q) => $q->where('orders.seller_internal_remark', 'like', $like($f['remark'])))
            ->when($f['from'] !== null, fn (Builder $q) => $q->where('orders.paid_at', '>=', $f['from'].' 00:00:00'))
            ->when($f['to'] !== null, fn (Builder $q) => $q->where('orders.paid_at', '<=', $f['to'].' 23:59:59'));

        $counts = [];
        foreach (self::TABS as $name => $statuses) {
            $counts[$name] = $base()->when($statuses !== [], fn (Builder $q) => $q->whereIn('orders.status', $statuses))->count();
        }

        $page = $base()
            ->when(self::TABS[$tab] !== [], fn (Builder $q) => $q->whereIn('orders.status', self::TABS[$tab]))
            ->with(['item.offer.gameCategory.type'])
            ->latest('orders.paid_at')
            ->paginate(20)
            ->withQueryString();

        $games = Game::query()->whereIn('id', Offer::query()->where('seller_profile_id', $profile->id)->join('game_categories', 'game_categories.id', '=', 'offers.game_category_id')->select('game_categories.game_id'))
            ->get()->map(fn (Game $g): array => ['id' => $g->id, 'name' => $g->translate('name', $locale)])->sortBy('name')->values()->all();

        return Inertia::render('Seller/Orders/Index', [
            'orders' => [
                'items' => array_map(static fn (Order $o): array => [
                    'publicId' => $o->public_id,
                    'number' => $o->number,
                    'title' => is_string($o->item->snapshot['title'] ?? null) ? $o->item->snapshot['title'] : '',
                    'type' => $o->item->offer->gameCategory->type->code->value,
                    'quantity' => $o->item->quantity,
                    'unitPrice' => $o->item->unit_price_amount,
                    'total' => $o->subtotal_amount,
                    'status' => $o->status->value,
                    'substatus' => $o->seller_substatus?->value,
                    'remark' => $o->seller_internal_remark,
                    'acquisitionCost' => $o->seller_acquisition_cost_amount,
                    'paidAt' => $o->paid_at?->toIso8601String(),
                ], $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
            'counts' => $counts,
            'balances' => app(Statements::class)->sellerBalances(self::user($request), 'USD'),
            'tab' => $tab,
            'tabs' => array_keys(self::TABS),
            'filters' => $f,
            'games' => $games,
            'types' => array_map(static fn (CategoryTypeCode $c): string => $c->value, CategoryTypeCode::cases()),
        ]);
    }

    public function show(Request $request, SecretVault $vault, string $publicId): Response
    {
        $profile = $this->seller($request);
        $order = $this->own($profile, $publicId);
        $locale = app()->getLocale();

        // Buyer inputs only after payment (spec 8.2).
        $inputs = [];
        $sealed = $order->sealedBuyerInputs();
        if ($sealed !== null && OrderRules::sellerSeesBuyerInputs($order)) {
            $decoded = json_decode($vault->open($sealed), true);
            $labels = [];
            foreach ($order->item->offer->gameCategory->buyerInputSchema()->present($locale) as $field) {
                $labels[$field['key']] = $field['label'];
            }
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $inputs[] = ['label' => $labels[$key] ?? (string) $key, 'value' => is_scalar($value) ? (string) $value : ''];
            }
        }

        return Inertia::render('Seller/Orders/Show', [
            'order' => [
                ...OrderPresenter::detail($order, $locale, "/seller/orders/{$order->public_id}/evidence"),
                'remark' => $order->seller_internal_remark,
                'acquisitionCost' => $order->seller_acquisition_cost_amount === null ? '' : (string) Money::ofMinor($order->seller_acquisition_cost_amount, $order->currency)->getAmount(),
                'buyerInputs' => $inputs,
                'buyer' => $order->buyer->username,
            ],
            'can' => [
                'accept' => $order->seller_substatus === SellerSubstatus::New,
                'inTrading' => in_array($order->seller_substatus, [SellerSubstatus::New, SellerSubstatus::Restocking, SellerSubstatus::Issue], true),
                'deliver' => OrderRules::sellerCanDeliver($order), // also when an auto delivery failed
                'cancel' => OrderRules::sellerCanCancel($order),
                'write' => $profile->status !== SellerStatus::Suspended,
            ],
        ]);
    }

    public function substatus(Request $request, OrderStateMachine $machine, string $publicId): RedirectResponse
    {
        $request->validate(['substatus' => ['required', Rule::in([SellerSubstatus::Restocking->value, SellerSubstatus::InTrading->value])]]);
        [$profile, $order] = $this->writable($request, $publicId);

        return $this->run(fn () => $machine->setSubstatus($order, SellerSubstatus::from($request->string('substatus')->toString()), ActorType::Seller, self::user($request)), 'order.seller.saved');
    }

    public function deliver(Request $request, DeliverOrder $deliver, string $publicId): RedirectResponse
    {
        $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'secrets' => ['nullable', 'string', 'max:200000'],
            'note' => ['nullable', 'string', 'max:1000', new NoContactInfo],
            'evidence' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        [, $order] = $this->writable($request, $publicId);
        $file = $request->file('evidence');

        return $this->run(fn () => $deliver->handle(
            $order,
            self::user($request),
            $request->integer('quantity'),
            $request->string('secrets')->toString(),
            is_string($n = $request->input('note')) ? $n : null,
            $file instanceof UploadedFile ? $file : null,
        ), 'order.seller.delivered');
    }

    public function milestone(Request $request, DeliverOrder $deliver, string $publicId): RedirectResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:160', new NoContactInfo],
            'percent' => ['required', 'integer', 'min:1', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000', new NoContactInfo],
        ]);
        [, $order] = $this->writable($request, $publicId);

        return $this->run(fn () => $deliver->milestone($order, self::user($request), $request->string('title')->toString(), $request->integer('percent'), is_string($n = $request->input('note')) ? $n : null), 'order.seller.saved');
    }

    /** Internal remark and acquisition cost: private bookkeeping, not order state (spec 1.8). */
    public function notes(Request $request, string $publicId): RedirectResponse
    {
        $request->validate([
            'remark' => ['nullable', 'string', 'max:500'],
            'acquisition_cost' => ['nullable', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ]);
        [, $order] = $this->writable($request, $publicId);
        $cost = $request->input('acquisition_cost');

        $order->forceFill([
            'seller_internal_remark' => is_string($r = $request->input('remark')) && trim($r) !== '' ? trim($r) : null,
            'seller_acquisition_cost_amount' => is_string($cost) && $cost !== '' ? Amount::toMinor(Money::of($cost, $order->currency)) : null,
        ])->save();

        return back()->with('success', __('order.seller.saved'));
    }

    public function cancel(Request $request, OrderStateMachine $machine, string $publicId): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:200']]);
        [, $order] = $this->writable($request, $publicId);

        if (! OrderRules::sellerCanCancel($order)) {
            return back()->withErrors(['order' => __('order.error.cannot_cancel')]);
        }

        return $this->run(fn () => $machine->cancel($order, 'seller_cancel: '.$request->string('reason')->toString(), ActorType::Seller, self::user($request)), 'order.seller.cancelled');
    }

    public function evidence(Request $request, string $publicId, int $evidence): StreamedResponse
    {
        return PurchaseOrderController::streamEvidence($this->own($this->seller($request), $publicId), $evidence);
    }

    /**
     * @return array{0: SellerProfile, 1: Order}
     */
    private function writable(Request $request, string $publicId): array
    {
        $profile = $this->seller($request);
        abort_if($profile->status === SellerStatus::Suspended, 403, (string) __('seller.error.suspended'));

        return [$profile, $this->own($profile, $publicId)];
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (CheckoutException $e) {
            return back()->withErrors([$e->field === 'checkout' ? 'order' : $e->field => $e->getMessage()]);
        }

        return back()->with('success', __($success));
    }

    private function own(SellerProfile $profile, string $publicId): Order
    {
        $order = Order::query()->where('public_id', $publicId)->where('seller_profile_id', $profile->id)->whereNotNull('paid_at')
            ->with([...OrderPresenter::relations(), 'item.offer.gameCategory.type'])->first();
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
