<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Risk\Models\BlocklistEntry;
use App\Domains\Risk\Models\RiskFlag;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Shared\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;
use Tests\Support\InMemoryCreditsWallet;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->wallet = new InMemoryCreditsWallet;
    app()->instance(CreditsWallet::class, $this->wallet);
    $this->actingAs($this->fx['buyer']);
});

// ── Credits (spec 9.2) ────────────────────────────────────────────────────

it('pays a credits-only order straight away, without a PSP payment', function (): void {
    $this->wallet->fund($this->fx['buyer'], 5000);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true, 'method' => null]))
        ->assertRedirect();

    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_credits_amount)->toBe(1030)
        ->and($order->paid_psp_amount)->toBe(0)
        ->and(Payment::query()->where('order_id', $order->id)->sole()->provider)->toBe(Payment::CREDITS)
        ->and($this->wallet->balances[$this->fx['buyer']->id])->toBe(3970);
});

it('splits credits and card, and gives the credits back if the card part fails', function (): void {
    $this->wallet->fund($this->fx['buyer'], 500);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true]));
    $order = CheckoutFixture::lastOrder($this->fx['buyer']);

    expect($order->paid_credits_amount)->toBe(500)
        ->and(CheckoutFixture::pspPayment($order)->amount)->toBe(530)
        ->and($this->wallet->balances[$this->fx['buyer']->id])->toBe(0);

    CheckoutFixture::webhook($order, PaymentStatus::Failed);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->wallet->balances[$this->fx['buyer']->id])->toBe(500);
});

it('refuses the order when the balance no longer covers the credits part', function (): void {
    $this->wallet->fund($this->fx['buyer'], 500);
    $racing = new class($this->wallet) implements CreditsWallet
    {
        public function __construct(private readonly InMemoryCreditsWallet $inner) {}

        public function available(User $user, string $currency): int
        {
            return $this->inner->available($user, $currency);
        }

        public function spend(User $user, int $amount, string $currency, string $idempotencyKey): void
        {
            $this->inner->balances[$user->id] = 100; // another spend landed in between
            $this->inner->spend($user, $amount, $currency, $idempotencyKey);
        }

        public function restore(User $user, int $amount, string $currency, string $idempotencyKey): void
        {
            $this->inner->restore($user, $amount, $currency, $idempotencyKey);
        }
    };
    app()->instance(CreditsWallet::class, $racing);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true]))->assertSessionHasErrors('checkout');
    expect(Order::query()->count())->toBe(0)->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5);
});

// ── Risk v1 (spec 17.1) ───────────────────────────────────────────────────

it('blocks checkouts from a blocklisted e-mail domain and records why', function (): void {
    $domain = substr((string) strrchr($this->fx['buyer']->email, '@'), 1);
    BlocklistEntry::query()->create(['type' => 'email_domain', 'value' => $domain, 'reason' => 'fraud ring']);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout');

    expect(Order::query()->count())->toBe(0)
        ->and(RiskFlag::query()->where('rule', 'checkout_blocked')->sole()->details['rules'])->toBe(['blocklist']);
});

it('reviews, then blocks, a buyer placing too many orders in an hour', function (): void {
    $this->fx['manual']->forceFill(['manual_stock_qty' => 50])->save();

    foreach (range(1, 5) as $i) {
        $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasNoErrors();
    }
    expect(Order::query()->orderBy('id')->pluck('risk_decision')->map->value->all())->toBe(['pass', 'pass', 'pass', 'review', 'review']);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout');
    expect(Order::query()->count())->toBe(5);
});

it('sends a risky order to payment review once captured; finance approves or rejects', function (): void {
    $this->fx['buyer']->forceFill(['created_at' => now()->subHours(2)])->save(); // new account
    $this->fx['manual']->forceFill(['unit_price_amount' => 4500])->save();     // $45 + fee > ... no: under $50

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $first = CheckoutFixture::lastOrder($this->fx['buyer']);
    expect($first->risk_decision?->value)->toBe('pass');

    $this->fx['manual']->forceFill(['unit_price_amount' => 4900])->save(); // $49 + $1.47 fee = $50.47 > $50
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout'); // first-order cap
    app(Settings::class)->set('max_order_amount_new_buyer', 100000);

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $risky = CheckoutFixture::lastOrder($this->fx['buyer']);
    expect($risky->risk_decision?->value)->toBe('review');

    CheckoutFixture::webhook($risky, PaymentStatus::Captured);
    expect($risky->refresh()->status)->toBe(OrderStatus::PaymentReview);

    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $this->actingAs($finance);
    Livewire::test(ListOrders::class)->callAction(TestAction::make('approve')->table($risky));
    expect($risky->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($risky->history()->latest('id')->first()?->actor_id)->toBe($finance->id)
        ->and(RiskFlag::query()->where('subject_id', $risky->id)->where('status', 'open')->count())->toBe(0);
});

it('rejecting a reviewed order cancels it, returns stock and credits, and flags the card part for refund', function (): void {
    $this->wallet->fund($this->fx['buyer'], 300);
    $this->fx['buyer']->forceFill(['password' => 'changed-pass-1'])->save(); // credential change → credits spend reviewed

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true]));
    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);
    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReview);

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    Livewire::test(ListOrders::class)->callAction(TestAction::make('reject')->table($order), ['reason' => 'Account takeover suspected']);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5)
        ->and($this->wallet->balances[$this->fx['buyer']->id])->toBe(300)
        ->and(RiskFlag::query()->where('rule', 'refund_required')->sole()->details['amount'])->toBe($order->paid_psp_amount);
});

it('never lets staff review their own purchase, and support cannot review at all', function (): void {
    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $this->fx['buyer']->forceFill(['created_at' => now()->subHour()])->save();
    $offer = Offer::factory()->create(['unit_price_amount' => 1000]);
    app(Settings::class)->set('risk_review_new_account_amount', 100);

    $this->actingAs($finance);
    $finance->forceFill(['created_at' => now()->subHour()])->save();
    $this->post('/checkout', CheckoutFixture::form($offer));
    $own = CheckoutFixture::lastOrder($finance);
    CheckoutFixture::webhook($own, PaymentStatus::Captured);
    expect($own->refresh()->status)->toBe(OrderStatus::PaymentReview);

    Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('approve')->table($own));

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('approve')->table($own));
});
