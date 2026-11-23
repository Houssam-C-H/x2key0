<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Actions\ApplyChargeback;
use App\Domains\Payments\Exceptions\ChargebackException;
use App\Domains\Payments\Models\Chargeback;
use App\Domains\Points\Support\Points;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->sellerUser = $this->fx['seller']->user;
    $this->finance = User::factory()->staff(StaffRole::Finance)->create();
    $this->clean = fn () => expect(app(VerifyLedger::class)->handle())->toBe([]);
});

it('opens once per case: CHARGEBACK journal, completed order frozen, completion blocked meanwhile', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    $payment = CheckoutFixture::pspPayment($order);
    $clearing = Ledger::clearing();

    $cb = app(ApplyChargeback::class)->open($payment, 'case-1', 1030, 1500, '10.4');
    expect(app(ApplyChargeback::class)->open($payment, 'case-1', 1030)->id)->toBe($cb->id);

    expect(Ledger::clearing())->toBe($clearing - 1030 - 1500)
        ->and(Ledger::revenue('EXP_CHARGEBACK_LOSS'))->toBe(-1030)
        ->and(Ledger::revenue('EXP_CHARGEBACK_FEES'))->toBe(-1500);

    expect(fn () => app(OrderStateMachine::class)->complete($order, ActorType::Buyer))->toThrow(CheckoutException::class);
    ($this->clean)();
});

it('lost on an unfinished order: escrow leaves, order closed as refunded, loss reduced', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    $cb = app(ApplyChargeback::class)->open(CheckoutFixture::pspPayment($order), 'case-2', 1030);

    app(ApplyChargeback::class)->lost($cb, $this->finance);

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->cancel_reason)->toBe('chargeback_lost')
        ->and(Ledger::escrow())->toBe(0)
        ->and($cb->refresh()->recovered_amount)->toBe(1000)
        ->and(Ledger::revenue('EXP_CHARGEBACK_LOSS'))->toBe(-30); // only the service charge is lost
    expect(fn () => app(ApplyChargeback::class)->won($cb, $this->finance))->toThrow(ChargebackException::class);
    ($this->clean)();
});

it('lost on a completed order: recovered from the seller hold, points reversed', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->fx['buyer']);
    $cb = app(ApplyChargeback::class)->open(CheckoutFixture::pspPayment($order), 'case-3', 1030);
    expect(Hold::query()->where('order_id', $order->id)->sole()->frozen_by)->toBe("chargeback:{$cb->id}");

    app(ApplyChargeback::class)->lost($cb, $this->finance);

    expect($cb->refresh()->recovered_amount)->toBe(920)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_PENDING'))->toBe(0)
        ->and(Ledger::revenue('EXP_CHARGEBACK_LOSS'))->toBe(-110)
        ->and(Points::balance($this->fx['buyer']->id))->toBe(0)
        ->and(Hold::query()->where('order_id', $order->id)->sole()->frozen_by)->toBeNull();
    ($this->clean)();
});

it('won: the money comes back and the seller hold resumes', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->fx['buyer']);
    $clearing = Ledger::clearing();
    $cb = app(ApplyChargeback::class)->open(CheckoutFixture::pspPayment($order), 'case-4', 1030, 1500);

    app(ApplyChargeback::class)->won($cb, $this->finance);

    expect($cb->refresh()->status)->toBe(Chargeback::WON)
        ->and(Ledger::clearing())->toBe($clearing - 1500) // the fee stays
        ->and(Ledger::revenue('EXP_CHARGEBACK_LOSS'))->toBe(0)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_PENDING'))->toBe(920)
        ->and(Hold::query()->where('order_id', $order->id)->sole()->frozen_by)->toBeNull();
    ($this->clean)();
});

it('refuses amounts above the payment and credits payments; only finance manages chargebacks', function (): void {
    $order = CheckoutFixture::deliveredOrder($this->fx);
    expect(fn () => app(ApplyChargeback::class)->open(CheckoutFixture::pspPayment($order), 'x', 99999))->toThrow(ChargebackException::class);

    expect(Gate::forUser($this->finance)->allows('create', Chargeback::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Support)->create())->allows('viewAny', Chargeback::class))->toBeFalse();
});
