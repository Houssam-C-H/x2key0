<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Support\Statements;
use App\Domains\Payouts\Actions\ManagePayoutMethods;
use App\Domains\Payouts\Actions\RequestWithdrawal;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\PayoutMethod;
use App\Domains\Payouts\Models\PayoutMethodType;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\StateMachines\WithdrawalStateMachine;
use App\Domains\Payouts\Support\WithdrawalRules;
use App\Shared\Support\Settings;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Withdrawal Management (spec 1.8): balance breakdown (Store Deposit, Revenue Reserve, Not Available, Available,
 * pending earnings, in transit), the withdraw wizard (method → amount → confirm with fee and payout), records with
 * status and remarks, and Withdrawal Account (payout methods).
 */
final class WithdrawalController
{
    public function index(Request $request, Statements $statements, WithdrawalRules $rules, Settings $settings): Response
    {
        $user = self::user($request);
        $range = $request->query('range') === 'all' ? null : now()->subMonths(3);

        $records = WithdrawalRequest::query()->where('user_id', $user->id)
            ->when($range !== null, fn ($q) => $q->where('requested_at', '>=', $range))
            ->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Seller/Withdrawals/Index', [
            'balances' => $statements->sellerBalances($user, 'USD'),
            'blockers' => $rules->blockers($user),
            'methods' => self::methodRows($user),
            'minimum' => $settings->int('withdrawal_min_amount'),
            'minimumFee' => $settings->int('withdrawal_fee_amount'),
            'range' => $range === null ? 'all' : '3m',
            'records' => [
                'data' => collect($records->items())->map(static fn (WithdrawalRequest $w): array => [
                    'id' => $w->public_id,
                    'number' => $w->number(),
                    'account' => $w->payout_label,
                    'amount' => $w->amount,
                    'fee' => $w->fee_amount,
                    'payout' => $w->payout_amount,
                    'currency' => $w->payout_currency,
                    'status' => $w->status->value,
                    'remarks' => $w->failure_reason ?? $w->remarks,
                    'requestedAt' => $w->requested_at->toIso8601String(),
                    'paidAt' => $w->paid_at?->toIso8601String(),
                    'canCancel' => $w->status->value === 'requested',
                ])->all(),
                'page' => $records->currentPage(),
                'lastPage' => $records->lastPage(),
            ],
            'idempotencyKey' => strtolower((string) Str::ulid()),
        ]);
    }

    public function store(Request $request, RequestWithdrawal $withdraw): RedirectResponse
    {
        $request->validate([
            'method' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $cents = Money::of($request->string('amount')->toString(), 'USD', roundingMode: RoundingMode::Down)->getMinorAmount()->toInt();

        try {
            $withdraw->handle(self::user($request), $request->integer('method'), $cents, $request->string('idempotency_key')->toString());
        } catch (PayoutException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect('/seller/withdrawals')->with('success', __('payout.requested'));
    }

    public function cancel(Request $request, WithdrawalStateMachine $machine, string $publicId): RedirectResponse
    {
        $user = self::user($request);
        $w = WithdrawalRequest::query()->where('public_id', $publicId)->where('user_id', $user->id)->first();
        abort_if($w === null, 404);

        try {
            $machine->cancel($w, $user);
        } catch (PayoutException $e) {
            return back()->withErrors(['withdrawal' => $e->getMessage()]);
        }

        return back()->with('success', __('payout.cancelled'));
    }

    public function methods(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('Seller/Withdrawals/Methods', [
            'methods' => self::methodRows($user),
            'catalog' => PayoutMethodType::query()->where('is_enabled', true)->orderBy('sort')->get()->map(static fn (PayoutMethodType $t): array => [
                'code' => $t->code,
                'name' => $t->name,
                'currencies' => $t->currencies,
                'fields' => $t->fields,
                'feeBps' => $t->fee_bps,
                'feeFixed' => $t->fee_fixed_amount,
                'min' => $t->min_amount,
            ])->all(),
            'max' => ManagePayoutMethods::MAX_METHODS,
        ]);
    }

    public function storeMethod(Request $request, ManagePayoutMethods $manage): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'max:24'],
            'currency' => ['required', 'string', 'size:3'],
            'details' => ['required', 'array'],
            'details.*' => ['nullable', 'string', 'max:191'],
        ]);

        $details = [];
        foreach ((array) $request->input('details') as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $details[$key] = $value;
            }
        }

        try {
            $manage->add(self::user($request), $request->string('type')->toString(), $request->string('currency')->toString(), $details);
        } catch (PayoutException $e) {
            return back()->withErrors(['details' => $e->getMessage()]);
        }

        return back()->with('success', __('payout.method_added'));
    }

    public function defaultMethod(Request $request, ManagePayoutMethods $manage, int $method): RedirectResponse
    {
        return $this->onMethod($request, $method, fn (User $u, PayoutMethod $m) => $manage->makeDefault($u, $m), 'payout.method_default');
    }

    public function removeMethod(Request $request, ManagePayoutMethods $manage, int $method): RedirectResponse
    {
        return $this->onMethod($request, $method, fn (User $u, PayoutMethod $m) => $manage->remove($u, $m), 'payout.method_removed');
    }

    /**
     * @param  callable(User, PayoutMethod): void  $action
     */
    private function onMethod(Request $request, int $id, callable $action, string $success): RedirectResponse
    {
        $user = self::user($request);
        $method = PayoutMethod::query()->usable()->where('user_id', $user->id)->whereKey($id)->first();
        abort_if($method === null, 404);

        try {
            $action($user, $method);
        } catch (PayoutException $e) {
            return back()->withErrors(['details' => $e->getMessage()]);
        }

        return back()->with('success', __($success));
    }

    /**
     * @return list<array{id: int, label: string, type: string, currency: string, isDefault: bool, feeBps: int, feeFixed: int, min: int}>
     */
    private static function methodRows(User $user): array
    {
        $catalog = PayoutMethodType::query()->get()->keyBy('code');

        return array_values(PayoutMethod::query()->usable()->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get()
            ->map(static function (PayoutMethod $m) use ($catalog): array {
                $type = $catalog->get($m->type);

                return [
                    'id' => $m->id,
                    'label' => $m->masked_label,
                    'type' => $m->type,
                    'currency' => $m->payout_currency,
                    'isDefault' => $m->is_default,
                    'feeBps' => $type instanceof PayoutMethodType ? $type->fee_bps : 0,
                    'feeFixed' => $type instanceof PayoutMethodType ? $type->fee_fixed_amount : 0,
                    'min' => $type instanceof PayoutMethodType ? $type->min_amount : 0,
                ];
            })->values()->all());
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
