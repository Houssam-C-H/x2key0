<?php

declare(strict_types=1);

use App\Domains\Delivery\Actions\DeliverOrder;
use App\Domains\Disputes\Actions\DisputeParticipation;
use App\Domains\Disputes\Actions\OpenDispute;
use App\Domains\Disputes\Actions\ResolveDispute;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Points\Support\Points;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Violations\Models\Violation;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Database\Seeders\ViolationRuleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

/*
| Acceptance (spec Part 25 M11): every dispute outcome leaves the order in the right state and the ledger exact.
| Fixture order: "Coins pack" $10.00 per unit + 3 % service charge (min $0.30), paid by the fake card.
*/

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    (new ViolationRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->sellerUser = $this->fx['seller']->user;
    $this->staff = User::factory()->staff(StaffRole::Support)->create();
    $this->delivered = fn (array $o = []) => CheckoutFixture::deliveredOrder($this->fx, 'manual', $o);
    $this->paid = function (array $o = []): Order {
        $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual'], $o));
        $order = CheckoutFixture::lastOrder($this->buyer);
        CheckoutFixture::webhook($order, PaymentStatus::Captured);

        return $order->refresh();
    };
    $this->open = fn (Order $order, DisputeReason $reason = DisputeReason::NotAsDescribed, DisputeOutcome $resolution = DisputeOutcome::RefundFull, int $qty = 1, ?User $by = null) => app(OpenDispute::class)->handle(
        $order, $by ?? $this->buyer, ['reason' => $reason, 'resolution' => $resolution, 'qty' => $qty, 'description' => 'The coins never arrived in my account.', 'to_credits' => false],
    );
    $this->resolve = fn (Dispute $d, DisputeOutcome $o, array $opts = []) => app(ResolveDispute::class)->handle($d, $o, DisputeRole::Staff, $this->staff, $opts);
    $this->clean = fn () => expect(app(VerifyLedger::class)->handle())->toBe([]);
});

it('opening a case moves it to awaiting the seller with a deadline, blocks completion and is the only open case', function (): void {
    $order = ($this->delivered)();
    $dispute = ($this->open)($order);

    expect($dispute->status)->toBe(DisputeStatus::AwaitingCounterparty)
        ->and($dispute->response_due_at?->isSameHour(now()->addHours(72)))->toBeTrue()
        ->and($dispute->history()->pluck('to_status')->map->value->all())->toBe(['open', 'awaiting_counterparty'])
        ->and(SellerStats::query()->where('seller_profile_id', $order->seller_profile_id)->value('disputes_opened'))->toBe(1);

    expect(fn () => app(OrderStateMachine::class)->complete($order, ActorType::Buyer))->toThrow(CheckoutException::class);
    expect(fn () => ($this->open)($order))->toThrow(DisputeException::class);

    // The confirmation timer keeps waiting while the case is open.
    $this->travel(80)->hours();
    Artisan::call('orders:process-timers');
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);
});

it('refund_full before completion: everything back to the card, order refunded, escrow empty', function (): void {
    $order = ($this->delivered)();
    $clearing = Ledger::clearing();
    $dispute = ($this->open)($order);

    app(DisputeParticipation::class)->accept($dispute, $this->sellerUser); // the seller accepts the request

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->item->refunded_qty)->toBe(1)
        ->and($order->item->delivered_qty)->toBe(0)
        ->and($order->refunded_psp_amount)->toBe(1030)
        ->and(Ledger::escrow())->toBe(0)
        ->and(Ledger::feeRevenue())->toBe(0)
        ->and(Ledger::clearing())->toBe($clearing - 1030)
        ->and($dispute->refresh()->status)->toBe(DisputeStatus::Resolved)
        ->and($dispute->decision?->outcome)->toBe(DisputeOutcome::RefundFull)
        ->and($dispute->decision?->decided_by)->toBeNull() // agreement
        ->and($dispute->decision?->refund_amount)->toBe(1030)
        ->and($order->history()->latest('id')->first()?->to_status)->toBe(OrderStatus::Refunded);
    ($this->clean)();
});

it('refund_partial before completion: one unit back, the rest settles on confirmation', function (): void {
    $order = ($this->delivered)(['quantity' => 3]); // $30.00 + $0.90 fee
    $dispute = ($this->open)($order, resolution: DisputeOutcome::RefundPartial, qty: 1);

    // The seller offers to refund one unit; the buyer accepts the proposal.
    app(DisputeParticipation::class)->propose($dispute, $this->sellerUser, DisputeOutcome::RefundPartial, 1, 'Sorry, one pack failed.');
    app(DisputeParticipation::class)->accept($dispute->refresh(), $this->buyer);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->item->refunded_qty)->toBe(1)
        ->and($order->item->delivered_qty)->toBe(2)
        ->and($order->refunded_psp_amount)->toBe(1000 + 30)
        ->and(Ledger::escrow())->toBe(2000)
        ->and($order->confirm_due_at?->gt(now()->addHours(23)))->toBeTrue();

    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    expect($order->refresh()->seller_net_amount)->toBe(1840) // 2 × $10 − 8 %
        ->and(Ledger::escrow())->toBe(0);
    ($this->clean)();
});

it('not received on a paid order after the deadline: ISSUE for the seller, escalated on silence, staff refund with seller fault', function (): void {
    $order = ($this->paid)();
    expect(fn () => ($this->open)($order, DisputeReason::NotReceived, DisputeOutcome::Redelivery))->toThrow(DisputeException::class); // before the deadline

    $this->travel(31)->minutes();
    $dispute = ($this->open)($order, DisputeReason::NotReceived, DisputeOutcome::Redelivery);
    expect($order->refresh()->seller_substatus)->toBe(SellerSubstatus::Issue)
        ->and($dispute->previous_substatus)->toBe('new');

    // The seller cannot cancel it away while the case is open.
    expect(fn () => app(OrderStateMachine::class)->cancel($order, 'seller_cancel: x', ActorType::Seller, $this->sellerUser))->toThrow(CheckoutException::class);

    $this->travel(73)->hours();
    Artisan::call('disputes:escalate-overdue');
    Artisan::call('disputes:escalate-overdue');
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Escalated)
        ->and($dispute->history()->where('to_status', 'escalated')->count())->toBe(1);

    ($this->resolve)($dispute, DisputeOutcome::RefundFull, ['seller_fault' => true, 'rationale' => 'No delivery proof.']);

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->seller_substatus)->toBeNull()
        ->and($dispute->refresh()->decision?->decided_by)->toBe($this->staff->id)
        ->and(SellerStats::query()->where('seller_profile_id', $order->seller_profile_id)->value('disputes_lost'))->toBe(1)
        ->and(Violation::query()->where('dispute_id', $dispute->id)->sole()->rule->code)->toBe('dispute_lost')
        ->and(Ledger::escrow())->toBe(0);
    ($this->clean)();
});

it('redelivery without delivered units gives the seller a new deadline and clears ISSUE', function (): void {
    $order = ($this->paid)();
    $this->travel(31)->minutes();
    $dispute = ($this->open)($order, DisputeReason::NotReceived, DisputeOutcome::Redelivery);

    ($this->resolve)($dispute, DisputeOutcome::Redelivery, ['qty' => 0]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->seller_substatus)->toBe(SellerSubstatus::InTrading)
        ->and($order->delivery_due_at?->gt(now()->addMinutes(29)))->toBeTrue();
});

it('replacement sends delivered units back to the seller; delivering again restarts confirmation', function (): void {
    $order = ($this->delivered)(['quantity' => 2]);
    $dispute = ($this->open)($order, DisputeReason::NotWorking, DisputeOutcome::Replacement, 1);
})->throws(DisputeException::class); // evidence is required for "not working"

it('replacement after agreement: delivering → delivered again, then settles the full order', function (): void {
    $order = ($this->delivered)(['quantity' => 2]);
    $dispute = ($this->open)($order, DisputeReason::NotAsDescribed, DisputeOutcome::Replacement, 1);
    app(DisputeParticipation::class)->accept($dispute, $this->sellerUser);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivering)
        ->and($order->item->delivered_qty)->toBe(1)
        ->and($order->confirm_due_at)->toBeNull();

    app(DeliverOrder::class)->handle($order, $this->sellerUser, 1, '', 'Replacement sent', null);
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);

    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    expect($order->refresh()->seller_net_amount)->toBe(1840)->and(Ledger::escrow())->toBe(0);
    ($this->clean)();
});

it('no_refund: the order resumes with at least 24 hours to confirm; buyer abuse is flagged after three losses', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $order = ($this->delivered)();
        $dispute = ($this->open)($order);
        ($this->resolve)($dispute, DisputeOutcome::NoRefund, ['rationale' => 'Delivered as described.']);
        expect($order->refresh()->status)->toBe(OrderStatus::Delivered)
            ->and($order->confirm_due_at?->gte(now()->addHours(23)))->toBeTrue();
    }

    expect(RiskFlag::query()->where('rule', 'buyer_dispute_abuse')->where('subject_id', $this->buyer->id)->count())->toBe(1);
    ($this->clean)();
});

it('the opener withdraws: case cancelled, order goes on', function (): void {
    $order = ($this->delivered)();
    $dispute = ($this->open)($order);

    expect(fn () => app(DisputeParticipation::class)->withdraw($dispute, $this->sellerUser))->toThrow(DisputeException::class);
    app(DisputeParticipation::class)->withdraw($dispute, $this->buyer);

    expect($dispute->refresh()->status)->toBe(DisputeStatus::Cancelled)
        ->and($dispute->decision?->outcome)->toBe(DisputeOutcome::Cancelled);
    app(OrderStateMachine::class)->complete($order->refresh(), ActorType::Buyer, $this->buyer);
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('after completion, while held: the hold freezes; refund_full takes the net back from the hold, reverses commission, fees and points', function (): void {
    $order = ($this->delivered)();
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    expect(Points::balance($this->buyer->id))->toBe(103)->and(Ledger::owedBy($this->sellerUser, 'SELLER_PENDING'))->toBe(920);

    $dispute = ($this->open)($order->refresh());
    expect(Hold::query()->where('order_id', $order->id)->sole()->frozen_by)->toBe("dispute:{$dispute->id}");

    // A frozen hold does not release, even after its date.
    $this->travel(15)->days();
    Artisan::call('holds:release');
    expect(Ledger::owedBy($this->sellerUser, 'SELLER_AVAILABLE'))->toBe(0);

    ($this->resolve)($dispute, DisputeOutcome::RefundFull, ['seller_fault' => true]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->item->refunded_qty)->toBe(1)
        ->and($order->refunded_psp_amount)->toBe(1030)
        ->and($order->seller_net_amount)->toBe(0)
        ->and($order->commission_amount)->toBe(0)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_PENDING'))->toBe(0)
        ->and(Ledger::revenue('REV_COMMISSION'))->toBe(0)
        ->and(Ledger::feeRevenue())->toBe(0)
        ->and(Points::balance($this->buyer->id))->toBe(0)
        ->and(Hold::query()->where('order_id', $order->id)->sole()->amount)->toBe(0)
        ->and($order->history()->latest('id')->first()?->reason)->toBe('dispute_refund');
    ($this->clean)();
});

it('after the hold released: Available money is frozen, and comes back when the seller wins', function (): void {
    config(['x2key.policy.post_completion_dispute_days' => 30]); // window longer than the 14-day hold
    $order = ($this->delivered)();
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    $this->travel(15)->days();
    Artisan::call('holds:release');
    expect(Ledger::owedBy($this->sellerUser, 'SELLER_AVAILABLE'))->toBe(828)   // 920 − 10 % reserve
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_RESERVE'))->toBe(92);

    $dispute = ($this->open)($order->refresh(), DisputeReason::NotWorking, DisputeOutcome::RefundFull, 1, null);
})->throws(DisputeException::class); // evidence required

it('after the hold released: freeze → no_refund → unfreeze leaves the seller exactly where they were', function (): void {
    config(['x2key.policy.post_completion_dispute_days' => 30]); // window longer than the 14-day hold
    $order = ($this->delivered)();
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    $this->travel(15)->days();
    Artisan::call('holds:release');

    $dispute = ($this->open)($order->refresh());
    expect($dispute->frozen_amount)->toBe(828)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_AVAILABLE'))->toBe(0)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_FROZEN'))->toBe(828)
        ->and(Hold::query()->where('order_id', $order->id)->where('kind', 'reserve')->sole()->frozen_by)->toBe("dispute:{$dispute->id}");

    ($this->resolve)($dispute, DisputeOutcome::NoRefund);

    expect(Ledger::owedBy($this->sellerUser, 'SELLER_AVAILABLE'))->toBe(828)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_FROZEN'))->toBe(0)
        ->and(Hold::query()->where('order_id', $order->id)->where('kind', 'reserve')->sole()->frozen_by)->toBeNull();
    ($this->clean)();
});

it('after the hold released: refund_partial takes from frozen money first, then the reserve hold', function (): void {
    config(['x2key.policy.post_completion_dispute_days' => 30]);
    $order = ($this->delivered)(['quantity' => 3]); // net 2760 after 8 %
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $this->buyer);
    $this->travel(15)->days();
    Artisan::call('holds:release'); // available 2484, reserve hold 276

    $dispute = ($this->open)($order->refresh(), resolution: DisputeOutcome::RefundPartial, qty: 1);
    expect($dispute->frozen_amount)->toBe(2484);

    ($this->resolve)($dispute, DisputeOutcome::RefundPartial, ['qty' => 1]);

    // Net share of one unit = 1000 − 80 commission = 920, all from the frozen money; the rest goes back.
    expect(Ledger::owedBy($this->sellerUser, 'SELLER_FROZEN'))->toBe(0)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_AVAILABLE'))->toBe(2484 - 920)
        ->and(Ledger::owedBy($this->sellerUser, 'SELLER_RESERVE'))->toBe(276)
        ->and($order->refresh()->seller_net_amount)->toBe(2760 - 920)
        ->and($order->commission_amount)->toBe(240 - 80)
        ->and(LedgerJournal::query()->where('type', 'REFUND')->count())->toBe(1);
    ($this->clean)();
});

it('only the parties see and act on a case; staff never decide their own order', function (): void {
    $order = ($this->delivered)();
    $dispute = ($this->open)($order);
    $stranger = User::factory()->create();

    expect(fn () => app(DisputeParticipation::class)->reply($dispute, $stranger, 'hello'))->toThrow(DisputeException::class);
    $this->actingAs($stranger)->get($dispute->path())->assertNotFound();
    $this->actingAs($this->buyer)->get($dispute->path())->assertOk();
    $this->actingAs($this->sellerUser)->get($dispute->path())->assertOk();

    expect(Gate::forUser($this->staff)->allows('decide', $dispute))->toBeTrue()
        ->and(Gate::forUser($this->buyer)->allows('decide', $dispute))->toBeFalse();

    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $own = Dispute::query()->whereKey($dispute->id)->firstOrFail();
    $order->forceFill(['buyer_id' => $admin->id])->save();
    expect(Gate::forUser($admin)->allows('decide', $own->refresh()))->toBeFalse();
});
