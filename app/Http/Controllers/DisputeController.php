<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Disputes\Actions\DisputeParticipation;
use App\Domains\Disputes\Actions\OpenDispute;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeEvidence;
use App\Domains\Disputes\Support\DisputePresenter;
use App\Domains\Disputes\Support\DisputeRules;
use App\Domains\Disputes\Support\EvidenceStore;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Shared\Validation\NoContactInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispute Center (spec 1.9 / Part 10 / Part 20): As Buyer / As Seller tabs with Open/Closed counters and quick
 * filters, opening a case from an order, the case thread (messages, evidence, proposals, accept, escalate,
 * withdraw) and Dispute Statistics. Only the two parties of the order see a case.
 */
final class DisputeController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $as = $request->query('as') === 'seller' ? DisputeRole::Seller : DisputeRole::Buyer;
        $closed = $request->query('status') === 'closed';
        $filter = is_string($f = $request->query('filter')) && in_array($f, ['response_required', 'under_review', 'awaiting_other'], true) ? $f : null;
        $search = is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 40) : '';

        $base = self::scope($user, $as);
        $query = (clone $base)->whereIn('status', $closed ? [DisputeStatus::Resolved->value, DisputeStatus::Cancelled->value] : DisputeStatus::openValues())
            ->with('order.item')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('order', fn (Builder $o) => $o->where('number', 'like', "%{$search}%"))
                ->orWhere('public_id', 'like', '%'.strtolower($search))))
            ->latest('updated_at');

        $rows = $query->limit(200)->get()->map(static fn (Dispute $d): array => DisputePresenter::row($d, $as));

        if ($filter !== null) {
            $rows = $rows->filter(static fn (array $r): bool => match ($filter) {
                'response_required' => $r['actionRequired'] === true,
                'under_review' => $r['status'] === DisputeStatus::Escalated->value,
                default => $r['actionRequired'] === false && in_array($r['status'], [DisputeStatus::AwaitingCounterparty->value, DisputeStatus::Negotiating->value], true),
            });
        }

        return Inertia::render('Disputes/Index', [
            'as' => $as->value,
            'status' => $closed ? 'closed' : 'open',
            'filter' => $filter,
            'q' => $search,
            'counts' => [
                'open' => (clone $base)->whereIn('status', DisputeStatus::openValues())->count(),
                'closed' => (clone $base)->whereIn('status', [DisputeStatus::Resolved->value, DisputeStatus::Cancelled->value])->count(),
            ],
            'rows' => $rows->values()->all(),
            'isSeller' => $user->isSeller(),
        ]);
    }

    public function create(Request $request, DisputeRules $rules, string $orderPublicId): Response|RedirectResponse
    {
        $user = self::user($request);
        [$order, $role] = $this->order($user, $orderPublicId);

        if (($open = Dispute::openFor($order->id)) !== null) {
            return redirect($open->path());
        }

        $reasons = $rules->reasons($order, $role);
        abort_if($reasons === [], 403);

        return Inertia::render('Disputes/Create', [
            'order' => [
                'publicId' => $order->public_id,
                'number' => $order->number,
                'status' => $order->status->value,
                'title' => is_string($t = $order->item->snapshot['title'] ?? null) ? $t : '',
                'quantity' => $order->item->quantity,
                'kept' => DisputeRules::keptUnits($order),
                'delivered' => $order->item->delivered_qty,
                'unitPrice' => $order->item->unit_price_amount,
                'currency' => $order->currency,
                'paidCredits' => $order->paid_credits_amount > 0,
            ],
            'role' => $role->value,
            'reasons' => array_map(static fn (DisputeReason $r): array => ['value' => $r->value, 'needsEvidence' => $r->needsEvidence()], $reasons),
            'resolutions' => array_map(static fn (DisputeOutcome $o): string => $o->value, DisputeRules::requestable($order)),
            'maxFiles' => EvidenceStore::MAX_FILES,
        ]);
    }

    public function store(Request $request, OpenDispute $open, string $orderPublicId): RedirectResponse
    {
        $user = self::user($request);
        [$order] = $this->order($user, $orderPublicId);

        $request->validate([
            'reason' => ['required', Rule::in(DisputeReason::values())],
            'resolution' => ['required', Rule::in(DisputeOutcome::requestableValues())],
            'qty' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'to_credits' => ['boolean'],
            'files' => ['array', 'max:'.EvidenceStore::MAX_FILES],
            'files.*' => EvidenceStore::FILE_RULES,
        ]);

        try {
            $dispute = $open->handle($order, $user, [
                'reason' => DisputeReason::from($request->string('reason')->toString()),
                'resolution' => DisputeOutcome::from($request->string('resolution')->toString()),
                'qty' => $request->integer('qty'),
                'description' => $request->string('description')->toString(),
                'to_credits' => $request->boolean('to_credits'),
            ], self::files($request));
        } catch (DisputeException $e) {
            return back()->withErrors(['dispute' => $e->getMessage()]);
        }

        return redirect($dispute->path())->with('success', __('dispute.opened'));
    }

    public function show(Request $request, string $publicId): Response
    {
        $user = self::user($request);
        [$dispute, $role] = $this->dispute($user, $publicId);
        $open = $dispute->status->isOpen();
        $negotiable = in_array($dispute->status, [DisputeStatus::AwaitingCounterparty, DisputeStatus::Negotiating], true);
        $order = $dispute->order;

        return Inertia::render('Disputes/Show', [
            'dispute' => DisputePresenter::detail($dispute, $role, app()->getLocale()),
            'can' => [
                'reply' => $open,
                'propose' => $negotiable,
                'accept' => $negotiable && (
                    ($dispute->proposal_by_role !== null && $dispute->proposal_by_role !== $role)
                    || ($dispute->proposal_resolution === null && $role !== $dispute->opened_by_role)
                ),
                'escalate' => $dispute->status === DisputeStatus::Negotiating,
                'withdraw' => $open && $role === $dispute->opened_by_role,
            ],
            'resolutions' => array_map(static fn (DisputeOutcome $o): string => $o->value, $order->status->value === 'completed'
                ? [DisputeOutcome::RefundFull, DisputeOutcome::RefundPartial]
                : DisputeRules::requestable($order)),
            'maxFiles' => EvidenceStore::MAX_FILES,
        ]);
    }

    public function reply(Request $request, DisputeParticipation $act, string $publicId): RedirectResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:2000', 'required_without:files'],
            'files' => ['array', 'max:'.EvidenceStore::MAX_FILES],
            'files.*' => EvidenceStore::FILE_RULES,
        ]);

        return $this->run($request, $publicId, fn (Dispute $d, User $u) => $act->reply($d, $u, $request->string('body')->toString(), self::files($request)), 'dispute.replied');
    }

    public function propose(Request $request, DisputeParticipation $act, string $publicId): RedirectResponse
    {
        $request->validate([
            'resolution' => ['required', Rule::in(DisputeOutcome::requestableValues())],
            'qty' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000', new NoContactInfo],
        ]);

        return $this->run($request, $publicId, fn (Dispute $d, User $u) => $act->propose($d, $u, DisputeOutcome::from($request->string('resolution')->toString()), $request->integer('qty'), $request->string('note')->toString()), 'dispute.proposed');
    }

    public function accept(Request $request, DisputeParticipation $act, string $publicId): RedirectResponse
    {
        return $this->run($request, $publicId, fn (Dispute $d, User $u) => $act->accept($d, $u), 'dispute.accepted');
    }

    public function escalate(Request $request, DisputeParticipation $act, string $publicId): RedirectResponse
    {
        return $this->run($request, $publicId, fn (Dispute $d, User $u) => $act->escalate($d, $u), 'dispute.escalated');
    }

    public function withdraw(Request $request, DisputeParticipation $act, string $publicId): RedirectResponse
    {
        return $this->run($request, $publicId, fn (Dispute $d, User $u) => $act->withdraw($d, $u), 'dispute.withdrawn');
    }

    public function evidence(Request $request, string $publicId, int $evidence): StreamedResponse
    {
        [$dispute] = $this->dispute(self::user($request), $publicId);
        $file = DisputeEvidence::query()->where('dispute_id', $dispute->id)->whereKey($evidence)->first();
        abort_if($file === null, 404);

        return Storage::disk('local')->response($file->file_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Dispute Statistics (spec 1.9): per game × category over a period — disputes, share of all disputes,
     * dispute rate (disputes / paid orders) and refund resolution rate. Sellers see their store; buyers their purchases.
     */
    public function stats(Request $request): Response
    {
        $user = self::user($request);
        $as = $request->query('as') === 'seller' && $user->isSeller() ? DisputeRole::Seller : DisputeRole::Buyer;
        $period = is_string($p = $request->query('period')) && in_array($p, ['month', '30d', '90d'], true) ? $p : '30d';
        $from = match ($period) {
            'month' => now()->startOfMonth(),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        $orders = Order::query()->whereNotNull('orders.paid_at')->where('orders.created_at', '>=', $from)
            ->when($as === DisputeRole::Seller, fn (Builder $q) => $q->where('orders.seller_profile_id', $user->sellerProfile->id ?? 0), fn (Builder $q) => $q->where('orders.buyer_id', $user->id))
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->groupBy('offers.game_category_id')
            ->select('offers.game_category_id', DB::raw('count(*) as orders'))
            ->pluck('orders', 'offers.game_category_id');

        $grouped = DB::table('disputes')->where('disputes.created_at', '>=', $from)
            ->whereIn('disputes.order_id', self::scopeOrders($user, $as)->toBase())
            ->join('order_items', 'order_items.id', '=', 'disputes.order_item_id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->leftJoin('dispute_decisions', 'dispute_decisions.dispute_id', '=', 'disputes.id')
            ->groupBy('offers.game_category_id')
            ->select('offers.game_category_id', DB::raw('count(*) as disputes'), DB::raw("sum(case when dispute_decisions.outcome in ('refund_full','refund_partial') then 1 else 0 end) as refunded"))
            ->get();

        $stats = [];
        foreach ($grouped as $row) {
            $values = (array) $row;
            $id = is_numeric($values['game_category_id'] ?? null) ? (int) $values['game_category_id'] : 0;
            $stats[$id] = [
                'disputes' => is_numeric($values['disputes'] ?? null) ? (int) $values['disputes'] : 0,
                'refunded' => is_numeric($values['refunded'] ?? null) ? (int) $values['refunded'] : 0,
            ];
        }

        $total = array_sum(array_column($stats, 'disputes'));
        $names = GameCategory::query()->with(['game', 'type'])->whereIn('id', array_keys($stats))->get()->keyBy('id');

        $rows = collect($stats)->map(static function (array $s, int $gcId) use ($orders, $total, $names): array {
            $count = $s['disputes'];
            $refunded = $s['refunded'];
            $ordersCount = is_numeric($o = $orders[$gcId] ?? 0) ? (int) $o : 0;
            $gc = $names->get($gcId);

            return [
                'title' => $gc instanceof GameCategory ? $gc->title(app()->getLocale()) : '#'.$gcId,
                'disputes' => $count,
                'shareBps' => $total === 0 ? 0 : intdiv($count * 10000, $total),
                'rateBps' => $ordersCount === 0 ? 0 : intdiv($count * 10000, $ordersCount),
                'refundRateBps' => $count === 0 ? 0 : intdiv($refunded * 10000, $count),
                'orders' => $ordersCount,
            ];
        })->sortByDesc('disputes')->values()->all();

        return Inertia::render('Disputes/Stats', [
            'as' => $as->value,
            'period' => $period,
            'rows' => $rows,
            'total' => $total,
            'isSeller' => $user->isSeller(),
        ]);
    }

    /**
     * @param  callable(Dispute, User): mixed  $action
     */
    private function run(Request $request, string $publicId, callable $action, string $success): RedirectResponse
    {
        $user = self::user($request);
        [$dispute] = $this->dispute($user, $publicId);

        try {
            $action($dispute, $user);
        } catch (DisputeException $e) {
            return back()->withErrors(['dispute' => $e->getMessage()]);
        }

        return back()->with('success', __($success));
    }

    /**
     * @return Builder<Dispute>
     */
    private static function scope(User $user, DisputeRole $as): Builder
    {
        return Dispute::query()->whereIn('order_id', self::scopeOrders($user, $as));
    }

    /**
     * @return Builder<Order>
     */
    private static function scopeOrders(User $user, DisputeRole $as): Builder
    {
        return Order::query()->select('id')->when(
            $as === DisputeRole::Seller,
            fn (Builder $q) => $q->where('seller_profile_id', $user->sellerProfile->id ?? 0),
            fn (Builder $q) => $q->where('buyer_id', $user->id),
        );
    }

    /**
     * @return array{0: Order, 1: DisputeRole}
     */
    private function order(User $user, string $publicId): array
    {
        $order = Order::query()->where('public_id', $publicId)->with(['item.offer', 'seller'])->first();
        $role = $order === null ? null : DisputeRules::roleOf($order, $user);
        abort_if($order === null || $role === null, 404);

        return [$order, $role];
    }

    /**
     * @return array{0: Dispute, 1: DisputeRole}
     */
    private function dispute(User $user, string $publicId): array
    {
        $dispute = Dispute::query()->where('public_id', $publicId)->with('order.seller')->first();
        $role = $dispute === null ? null : DisputeRules::roleOf($dispute->order, $user);
        abort_if($dispute === null || $role === null, 404);

        return [$dispute, $role];
    }

    /**
     * @return list<UploadedFile>
     */
    private static function files(Request $request): array
    {
        $files = $request->file('files');

        return is_array($files) ? array_values($files) : [];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
