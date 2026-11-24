<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Checkout\Actions\CreateOrder;
use App\Domains\Checkout\Data\CheckoutRequest;
use App\Domains\Checkout\Support\QuoteCalculator;
use App\Domains\Coupons\Support\AppliedCoupons;
use App\Domains\Coupons\Support\CouponPricing;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Payments\Support\PaymentRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Checkout (spec 1.6, 9, Part 20 `POST /checkout`): one offer, quantity, buyer inputs, payment method,
 * credits toggle. The page carries a fresh idempotency key, so a double submit lands on the same order.
 */
final class CheckoutController
{
    public function show(Request $request, QuoteCalculator $quotes, PaymentRouter $router, CreditsWallet $wallet, CouponPricing $coupons): Response|RedirectResponse
    {
        $buyer = self::user($request);
        $locale = app()->getLocale();
        $publicId = is_string($p = $request->query('offer')) ? $p : '';

        $offer = Offer::query()->live()->where('offers.public_id', $publicId)->with(OfferPresenter::publicRelations())->first();
        abort_if($offer === null, 404);

        if ($offer->seller->user_id === $buyer->id) {
            return redirect($offer->path($locale))->with('error', __('checkout.error.own_offer'));
        }

        $qty = max($offer->min_qty, $request->integer('qty', $offer->min_qty));
        $credits = $wallet->available($buyer, $offer->currency);
        $useCredits = $credits > 0 && $request->boolean('credits');

        // Coupons (spec 14.1): the best eligible store + platform coupon are pre-selected; `coupons=none` clears them.
        $listUnit = $quotes->quote($offer, $qty, null, 0)->listUnitPrice;
        $eligible = $coupons->eligible($offer, $qty, $listUnit, $buyer, $locale);
        $selected = self::selectedCoupons($request, $eligible);
        try {
            $applied = $coupons->apply($offer, $qty, $listUnit, $buyer, $selected);
        } catch (CheckoutException) {
            $applied = AppliedCoupons::none();
            $selected = [];
        }

        $quoteNoMethod = $quotes->quote($offer, $qty, null, $useCredits ? $credits : 0, $applied);
        $methods = $quoteNoMethod->pspDue > 0 ? $router->available($buyer->country, $offer->currency, $quoteNoMethod->pspDue) : [];
        $methodCode = is_string($m = $request->query('method')) ? $m : ($methods[0]->code ?? null);
        $method = collect($methods)->first(fn (PaymentMethod $pm): bool => $pm->code === $methodCode);

        return Inertia::render('Checkout/Show', [
            'offer' => OfferPresenter::row($offer, $locale),
            'gameCategory' => $offer->gameCategory->title($locale),
            'quantity' => $qty,
            'quote' => $quotes->quote($offer, $qty, $method, $useCredits ? $credits : 0, $applied)->toArray(),
            'coupons' => ['eligible' => $eligible, 'selected' => $selected],
            'methods' => array_map(static fn (PaymentMethod $pm): array => [
                'code' => $pm->code,
                'name' => $pm->translate('name', $locale),
                'feeBps' => $pm->fee_bps,
                'feeFixed' => $pm->fee_fixed,
            ], $methods),
            'method' => $method?->code,
            'credits' => ['available' => $credits, 'use' => $useCredits],
            'buyerInputs' => $offer->gameCategory->buyerInputSchema()->present($locale),
            'idempotencyKey' => strtolower((string) Str::ulid()),
        ]);
    }

    public function store(Request $request, CreateOrder $action): RedirectResponse|HttpResponse
    {
        $request->validate([
            'offer' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'buyer_inputs' => ['array'],
            'buyer_inputs.*' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:32'],
            'use_credits' => ['boolean'],
            'coupons' => ['array', 'max:2'],
            'coupons.*' => ['string', 'size:26'],
            'idempotency_key' => ['required', 'string', 'size:26', 'alpha_num'],
        ]);

        $inputs = [];
        $raw = $request->input('buyer_inputs');
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value) || $value === null)) {
                $inputs[$key] = $value;
            }
        }

        $buyer = self::user($request);

        try {
            $order = $action->handle($buyer, new CheckoutRequest(
                offerPublicId: $request->string('offer')->toString(),
                quantity: $request->integer('quantity'),
                buyerInputs: $inputs,
                methodCode: is_string($m = $request->input('method')) ? $m : null,
                useCredits: $request->boolean('use_credits'),
                idempotencyKey: strtolower($request->string('idempotency_key')->toString()),
                ip: $request->ip(),
                country: $buyer->country,
                couponClaimIds: self::claimIds($request->input('coupons')),
            ));
        } catch (CheckoutException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        // Unpaid: send the buyer to the PSP page; otherwise to the order.
        if ($order->status === OrderStatus::PendingPayment) {
            $payment = Payment::query()->where('order_id', $order->id)->where('provider', '!=', Payment::CREDITS)->latest('id')->first();
            if ($payment !== null && $payment->redirect_url !== null && ! $payment->status->isFinal() && $payment->status !== PaymentStatus::Created) {
                return Inertia::location($payment->redirect_url);
            }
        }

        return redirect($order->path());
    }

    /**
     * Claim ids picked on the page (`coupons=a,b` or `none`), otherwise the best of each kind.
     *
     * @param  list<array{id: string, issuer: string, label: string, name: string, discount: int}>  $eligible
     * @return list<string>
     */
    private static function selectedCoupons(Request $request, array $eligible): array
    {
        $raw = $request->query('coupons');
        if (is_string($raw)) {
            $ids = array_column($eligible, 'id');

            return $raw === 'none' ? [] : array_values(array_intersect(array_slice(explode(',', $raw), 0, 2), $ids));
        }

        $best = [];
        foreach ($eligible as $coupon) {
            $best[$coupon['issuer']] ??= $coupon['id']; // eligible() is sorted best first per kind
        }

        return array_values($best);
    }

    /**
     * @return list<string>
     */
    private static function claimIds(mixed $raw): array
    {
        return array_values(array_filter(is_array($raw) ? $raw : [], 'is_string'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
