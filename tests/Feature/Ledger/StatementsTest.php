<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Refund;
use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Points\Support\Points;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->actingAs($this->buyer);
});

it('Credits Balance: the balance and the month log, with a running balance, equal the ledger', function (): void {
    Ledger::fundCredits($this->buyer, 2500);
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true, 'method' => null])); // spends $10.30

    $this->get('/buy/credits')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Buy/Credits')
        ->where('balance', 2500 - 1030)
        ->where('balance', Ledger::credits($this->buyer))
        ->has('rows', 2)
        ->where('rows.0.journalType', 'CREDITS_SPENT')   // newest first
        ->where('rows.0.amount', -1030)
        ->where('rows.0.balance', 1470)
        ->where('rows.0.order', fn ($n) => str_starts_with($n, 'X2K'))
        ->where('rows.1.journalType', 'PROMO_CREDIT')
        ->where('rows.1.amount', 2500)
        ->where('rows.1.balance', 2500));
});

it('Credits Balance: an earlier month starts from the opening balance of the earlier months', function (): void {
    Ledger::fundCredits($this->buyer, 700);
    $this->travel(40)->days();
    Ledger::fundCredits($this->buyer, 300);

    $this->get('/buy/credits')->assertInertia(fn (Assert $p) => $p->where('balance', 1000)->has('rows', 1)->where('rows.0.amount', 300)->where('rows.0.balance', 1000));
    $this->get('/buy/credits?month='.now()->subDays(40)->format('Y-m'))->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.balance', 700));
    $this->get('/buy/credits')->assertInertia(fn (Assert $p) => $p->has('months', 2));
});

it('Points Record: earn on completion and reversal, with the current score', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    $this->post("/buy/orders/{$order->public_id}/confirm");

    $this->get('/buy/points')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Buy/Points')
        ->where('points', 103)
        ->where('points', Points::balance($this->buyer->id))
        ->has('rows', 1)
        ->where('rows.0.type', 'earn_order')
        ->where('rows.0.amount', 103)
        ->where('rows.0.order', $order->number));

    app(AwardOrderPoints::class)->reverse($order);
    $this->get('/buy/points')->assertInertia(fn (Assert $p) => $p->where('points', 0)->has('rows', 2)->where('rows.0.type', 'reverse')->where('rows.0.amount', -103));
});

it('Funding Details: card payments out, card refunds and credits in, totals and filters', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);           // card payment −10.30
    $cancelled = (function () {
        $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));
        $o = CheckoutFixture::lastOrder($this->buyer);
        CheckoutFixture::webhook($o, PaymentStatus::Captured);

        return $o->refresh();
    })();                                                          // −10.30
    app(OrderStateMachine::class)->cancel($cancelled, 'seller_cancel: test', ActorType::Seller, $this->fx['seller']->user); // +10.30 card refund
    Ledger::fundCredits($this->buyer, 500);                        // +5.00 promo

    $this->get('/buy/funding')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Buy/Funding')
        ->where('inflow', 1030 + 500)
        ->where('outflow', 1030 + 1030)
        ->has('rows', 4));

    $this->get('/buy/funding?type=in')->assertInertia(fn (Assert $p) => $p->has('rows', 2)->where('outflow', 0));
    $this->get('/buy/funding?type=out')->assertInertia(fn (Assert $p) => $p->has('rows', 2)->where('inflow', 0));
    expect(Refund::query()->count())->toBe(1);
    expect($order->number)->toStartWith('X2K');
});

it('Funding Details: CSV export of the same statement', function (): void {
    CheckoutFixture::deliveredOrder($this->fx);
    Ledger::fundCredits($this->buyer, 500);

    $response = $this->get('/buy/funding/export')->assertOk();
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('funding-'.now()->format('Y-m').'.csv')
        ->and($lines[0])->toBe('date,order,type,amount,currency')
        ->and($lines)->toHaveCount(3)
        ->and(implode("\n", $lines))->toContain('-10.30')->toContain('5.00');
});

it('statements show the buyer\'s own data only', function (): void {
    Ledger::fundCredits($this->buyer, 900);

    $other = User::factory()->create();
    $this->actingAs($other)->get('/buy/credits')->assertInertia(fn (Assert $p) => $p->where('balance', 0)->has('rows', 0));
    $this->get('/buy/funding')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    $this->get('/buy/points')->assertInertia(fn (Assert $p) => $p->where('points', 0));
});
