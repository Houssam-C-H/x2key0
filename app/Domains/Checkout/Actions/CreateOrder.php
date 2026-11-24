<?php

declare(strict_types=1);

namespace App\Domains\Checkout\Actions;

use App\Domains\Checkout\Data\CheckoutRequest;
use App\Domains\Checkout\Support\Quote;
use App\Domains\Checkout\Support\QuoteCalculator;
use App\Domains\Coupons\Actions\CouponRedemptions;
use App\Domains\Coupons\Support\AppliedCoupons;
use App\Domains\Coupons\Support\CouponPricing;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Orders\Support\OrderNumber;
use App\Domains\Orders\Support\StockReservations;
use App\Domains\Payments\Actions\CapturePath;
use App\Domains\Payments\Actions\StartPayment;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Events\PaymentCaptured;
use App\Domains\Payments\Exceptions\InsufficientCredits;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Payments\Support\PaymentRouter;
use App\Domains\Risk\Enums\RiskDecision;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Risk\Support\RiskEvaluator;
use App\Domains\Storage\Contracts\SecretVault;
use App\Shared\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checkout (spec 6.1 create → pending_payment, 9.2, 9.3 #1): validates the purchase, prices it, runs the
 * checkout risk rules, then in one transaction numbers the order, freezes an offer snapshot, seals the buyer
 * inputs, reserves stock, spends credits and opens the payment. After commit the PSP payment is created —
 * or, when credits cover everything, the order goes straight down the capture path.
 *
 * Idempotent per (buyer, idempotency key): a double submit returns the first order.
 */
final class CreateOrder
{
    public function __construct(
        private readonly QuoteCalculator $quotes,
        private readonly RiskEvaluator $risk,
        private readonly StockReservations $reservations,
        private readonly OrderStateMachine $machine,
        private readonly CreditsWallet $wallet,
        private readonly PaymentRouter $router,
        private readonly SecretVault $vault,
        private readonly StartPayment $startPayment,
        private readonly CapturePath $capture,
        private readonly Settings $settings,
        private readonly CouponPricing $coupons,
        private readonly CouponRedemptions $redemptions,
    ) {}

    public function handle(User $buyer, CheckoutRequest $request): Order
    {
        $existing = self::existing($buyer, $request->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        if ($buyer->status !== UserStatus::Active) {
            throw CheckoutException::because('checkout.error.account');
        }

        $offer = Offer::query()->live()->where('offers.public_id', $request->offerPublicId)->with([...OfferPresenter::publicRelations(), 'gameCategory.game'])->first();
        if ($offer === null) {
            throw CheckoutException::because('checkout.error.unavailable');
        }
        if ($offer->seller->user_id === $buyer->id) {
            throw CheckoutException::because('checkout.error.own_offer');
        }

        $this->assertQuantity($offer, $request->quantity);
        $inputs = $this->buyerInputs($offer, $request->buyerInputs);

        $listUnit = $this->quotes->quote($offer, $request->quantity, null, 0)->listUnitPrice;
        $applied = $this->coupons->apply($offer, $request->quantity, $listUnit, $buyer, $request->couponClaimIds);

        $credits = $request->useCredits ? $this->wallet->available($buyer, $offer->currency) : 0;
        $preview = $this->quotes->quote($offer, $request->quantity, null, $credits, $applied);
        $method = $preview->pspDue > 0 ? $this->method($request, $offer->currency, $preview->pspDue) : null;
        $quote = $method === null ? $preview : $this->quotes->quote($offer, $request->quantity, $method, $credits, $applied);

        if (! $this->hasCompletedOrder($buyer) && $quote->total > $this->settings->int('max_order_amount_new_buyer')) {
            throw CheckoutException::because('checkout.error.new_buyer_limit', ['amount' => number_format($this->settings->int('max_order_amount_new_buyer') / 100, 2)]);
        }

        $risk = $this->risk->atCheckout($buyer, $offer, $quote, $request->ip);
        if ($risk->decision === RiskDecision::Block) {
            RiskFlag::raise('user', $buyer->id, 'checkout_blocked', $risk->score, ['rules' => $risk->rules(), 'offer_id' => $offer->id, 'ip' => $request->ip]);
            throw CheckoutException::because('checkout.error.blocked');
        }

        try {
            $order = DB::transaction(fn (): Order => $this->open($buyer, $offer, $quote, $method, $inputs, $request, $risk->decision, $risk->score, $risk->rules(), $applied));
        } catch (UniqueConstraintViolationException $e) {
            return self::existing($buyer, $request->idempotencyKey) ?? throw $e;
        } catch (InsufficientCredits) {
            throw CheckoutException::because('checkout.error.credits');
        }

        $this->machine->refreshStock($order);

        if ($order->pspDue() === 0) {
            return $this->capture->afterCapture($order, null);
        }

        $payment = Payment::query()->where('order_id', $order->id)->where('provider', '!=', Payment::CREDITS)->sole();
        $this->startPayment->handle($order, $payment);

        return $order->refresh();
    }

    /**
     * @param  array<string, string|int|float|null>  $inputs
     * @param  list<string>  $rules
     */
    private function open(User $buyer, Offer $offer, Quote $quote, ?PaymentMethod $method, array $inputs, CheckoutRequest $request, RiskDecision $decision, int $score, array $rules, AppliedCoupons $applied): Order
    {
        $due = now()->addMinutes($this->settings->int('payment_window_minutes'));
        $sealed = $inputs === [] ? null : $this->vault->seal(json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $order = new Order;
        $order->forceFill([
            'number' => OrderNumber::next(),
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $offer->seller_profile_id,
            'idempotency_key' => $request->idempotencyKey,
            'status' => OrderStatus::PendingPayment,
            'currency' => $quote->currency,
            'subtotal_amount' => $quote->subtotal,
            'discount_platform_amount' => $quote->discountPlatform,
            'discount_seller_amount' => $quote->discountSeller,
            'buyer_fee_amount' => $quote->buyerFee,
            'addon_fee_amount' => $quote->addonFee,
            'method_fee_amount' => $quote->methodFee,
            'tax_amount' => $quote->tax,
            'total_amount' => $quote->total,
            'paid_psp_amount' => 0,
            'paid_credits_amount' => 0,
            'commission_bps' => $quote->commissionBps,
            'commission_amount' => $quote->commission,
            'seller_net_amount' => $quote->sellerNet,
            'buyer_inputs_ciphertext' => $sealed?->ciphertext,
            'buyer_inputs_dek_wrapped' => $sealed?->wrappedKey,
            'buyer_country' => $request->country,
            'ip' => $request->ip,
            'risk_score' => $score,
            'risk_decision' => $decision,
            'payment_due_at' => $due,
        ])->save();

        $item = new OrderItem;
        $item->forceFill([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'product_id' => $offer->product_id,
            'quantity' => $quote->quantity,
            'unit_price_amount' => $quote->unitPrice, // seller-side, after a store coupon
            'snapshot' => [...self::snapshot($offer), 'list_unit_price_amount' => $quote->listUnitPrice],
        ])->save();

        $this->redemptions->redeem($order, $applied);
        $this->reservations->reserve($item, $offer, $quote->quantity, $due);
        OrderTimer::query()->create(['order_id' => $order->id, 'type' => OrderTimerType::PaymentExpiry, 'due_at' => $due]);
        $this->machine->opened($order, $buyer);

        if ($rules !== []) {
            RiskFlag::raise('order', $order->id, 'checkout_rules', $score, ['rules' => $rules, 'decision' => $decision->value]);
        }

        if ($quote->credits > 0) {
            $this->wallet->spend($buyer, $quote->credits, $quote->currency, "CREDITS_SPENT:order:{$order->id}", $order);
            $credits = new Payment;
            $credits->forceFill([
                'order_id' => $order->id,
                'provider' => Payment::CREDITS,
                'method_code' => Payment::CREDITS,
                'status' => PaymentStatus::Captured,
                'amount' => $quote->credits,
                'currency' => $quote->currency,
                'captured_at' => now(),
            ])->save();
            $order->forceFill(['paid_credits_amount' => $quote->credits])->save();
            PaymentCaptured::dispatch($credits->id);
        }

        if ($quote->pspDue > 0 && $method !== null) {
            (new Payment)->forceFill([
                'order_id' => $order->id,
                'provider' => $method->provider,
                'method_code' => $method->code,
                'status' => PaymentStatus::Created,
                'amount' => $quote->pspDue,
                'currency' => $quote->currency,
                'fee_amount' => $quote->methodFee,
            ])->save();
        }

        return $order->refresh();
    }

    private function assertQuantity(Offer $offer, int $qty): void
    {
        $max = min($offer->max_qty ?? PHP_INT_MAX, $offer->stock());

        if ($qty < $offer->min_qty || $qty > $max || ($offer->integer_multiple && $qty % $offer->min_qty !== 0)) {
            throw CheckoutException::because('checkout.error.quantity', ['min' => $offer->min_qty, 'max' => max($offer->min_qty, $max)], 'quantity');
        }
    }

    /**
     * Validates the game×category buyer inputs (spec 5.2 / 8.2) and keeps only declared keys.
     *
     * @param  array<string, string|int|float|null>  $inputs
     * @return array<string, string|int|float|null>
     */
    private function buyerInputs(Offer $offer, array $inputs): array
    {
        $schema = $offer->gameCategory->buyerInputSchema();
        $validator = Validator::make(['buyer_inputs' => $inputs], $schema->rules());

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $clean = [];
        foreach ($schema->fields() as $field) {
            $value = $inputs[$field['key']] ?? null;
            if ($value !== null && $value !== '') {
                $clean[$field['key']] = is_string($value) ? trim($value) : $value;
            }
        }

        return $clean;
    }

    private function method(CheckoutRequest $request, string $currency, int $amount): PaymentMethod
    {
        foreach ($this->router->available($request->country, $currency, $amount) as $method) {
            if ($method->code === $request->methodCode) {
                return $method;
            }
        }

        throw CheckoutException::because('checkout.error.method', field: 'method');
    }

    private function hasCompletedOrder(User $buyer): bool
    {
        return Order::query()->where('buyer_id', $buyer->id)->where('status', OrderStatus::Completed)->exists();
    }

    /**
     * Frozen copy of the offer at purchase ("view snapshot", spec 1.6).
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Offer $offer): array
    {
        return [
            'offer_public_id' => $offer->public_id,
            'title' => $offer->title,
            'description' => $offer->description,
            'game_category' => $offer->gameCategory->title('en'),
            'game_category_id' => $offer->game_category_id,
            'product' => $offer->product?->translate('name', 'en'),
            'delivery_method' => $offer->delivery_method->value,
            'fulfillment' => $offer->fulfillment->value,
            'delivery_time_minutes' => $offer->delivery_time_minutes,
            'attribute_values' => $offer->attribute_values,
            'unit_price_amount' => $offer->unit_price_amount,
            'quantity_tiers' => $offer->quantity_tiers,
            'currency' => $offer->currency,
            'insurance_days' => $offer->insurance_days,
            'account_recovery_protection_days' => $offer->account_recovery_protection_days,
            'offer_version' => $offer->version,
            'seller' => ['store_name' => $offer->seller->store_name, 'slug' => $offer->seller->slug],
        ];
    }

    private static function existing(User $buyer, string $key): ?Order
    {
        return Order::query()->where('buyer_id', $buyer->id)->where('idempotency_key', $key)->first();
    }
}
