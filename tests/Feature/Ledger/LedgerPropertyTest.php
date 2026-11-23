<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;
use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Points\Support\Points;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

/*
| Property tests (spec 24 "Ledger/points property tests: random op sequences → zero-sum, projections =
| postings, no negative from user spend"). Sequences are random but seeded, so a failure reproduces.
*/

/**
 * Money that must sit in escrow given the orders' states: the seller-side price of paid/in-review orders,
 * the credits part of orders still awaiting payment, and captures for orders already closed.
 */
function expectedEscrow(): int
{
    $paid = Order::query()->whereIn('status', [OrderStatus::Paid->value, OrderStatus::PaymentReview->value, OrderStatus::Delivering->value, OrderStatus::Delivered->value])->get()
        ->sum(fn (Order $o): int => $o->subtotal_amount - $o->discount_seller_amount);
    $pending = (int) Order::query()->where('status', OrderStatus::PendingPayment)->sum('paid_credits_amount');

    return $paid + $pending; // late captures are refunded at once, so they net out
}

it('keeps the money ledger consistent through random checkouts, payments, failures and expiries', function (int $seed): void {
    mt_srand($seed);
    $fx = CheckoutFixture::build();
    $fx['manual']->forceFill(['manual_stock_qty' => 500])->save();
    $offers = [$fx['manual'], $fx['auto'], Offer::factory()->create(['unit_price_amount' => mt_rand(100, 9000), 'manual_stock_qty' => 500])];
    $buyers = [$fx['buyer'], User::factory()->create(['created_at' => now()->subYear()]), User::factory()->create(['created_at' => now()->subYear()])];
    $granted = [];

    foreach (range(1, 40) as $step) {
        $buyer = $buyers[mt_rand(0, 2)];
        $this->actingAs($buyer);

        match (mt_rand(1, 7)) {
            1 => (function () use ($buyer, &$granted): void {
                $amount = mt_rand(50, 4000);
                Ledger::fundCredits($buyer, $amount);
                $granted[$buyer->id] = ($granted[$buyer->id] ?? 0) + $amount;
            })(),
            2, 3, 4 => $this->post('/checkout', CheckoutFixture::form($offers[mt_rand(0, 2)], [
                'use_credits' => mt_rand(0, 1) === 1,
                'method' => mt_rand(0, 3) === 0 ? 'fake_wallet' : 'fake_card',
                'buyer_inputs' => ['player_id' => '12345678'],
            ])),
            5, 6 => (function (): void {
                $order = Order::query()->where('status', OrderStatus::PendingPayment)->inRandomOrder()->first();
                $payment = $order === null ? null : Payment::query()->where('order_id', $order->id)->where('provider', 'fake')->first();
                if ($order !== null && $payment !== null && $payment->provider_payment_id !== null) {
                    CheckoutFixture::webhook($order, mt_rand(0, 3) === 0 ? PaymentStatus::Failed : PaymentStatus::Captured);
                }
            })(),
            default => (function (): void {
                $this->travel(mt_rand(5, 40))->minutes();
                Artisan::call('orders:expire-unpaid');
            })(),
        };

        // Invariants after every step.
        expect(app(VerifyLedger::class)->handle())->toBe([], "seed {$seed}, step {$step}");
        foreach ($buyers as $b) {
            expect(Ledger::credits($b))->toBeGreaterThanOrEqual(0);
        }
        expect(Ledger::escrow())->toBe(expectedEscrow(), "escrow at seed {$seed}, step {$step}")
            ->and(Ledger::clearing())->toBe((int) Payment::query()->where('provider', 'fake')->where('status', PaymentStatus::Captured)->sum('amount') - (int) Refund::query()->where('destination', 'original_method')->sum('amount'))
            ->and($fx['manual']->refresh()->manual_stock_qty)->toBeGreaterThanOrEqual(0);
    }

    // Credits are conserved: what was granted is either still owed or sits in live orders.
    foreach ($buyers as $b) {
        $inOrders = (int) Order::query()->where('buyer_id', $b->id)->whereNot('status', OrderStatus::Cancelled)->sum('paid_credits_amount');
        expect(Ledger::credits($b) + $inOrders)->toBe($granted[$b->id] ?? 0);
    }
})->with([11, 23, 47]);

it('keeps the points ledger zero-sum through random earns and reversals', function (int $seed): void {
    mt_srand($seed);
    (new PointsRuleSeeder)->run();
    $award = app(AwardOrderPoints::class);
    $fx = CheckoutFixture::build();
    $fx['manual']->forceFill(['manual_stock_qty' => 500])->save();
    $this->actingAs($fx['buyer']);

    $expected = 0;
    foreach (range(1, 25) as $step) {
        if (mt_rand(0, 2) > 0 || Order::query()->count() === 0) {
            $this->travel(2)->hours(); // stay under the orders-per-hour risk limit
            $before = Order::query()->count();
            $this->post('/checkout', CheckoutFixture::form($fx['manual'], ['quantity' => mt_rand(1, 3)]));
            expect(Order::query()->count())->toBe($before + 1);
            $order = CheckoutFixture::lastOrder($fx['buyer']);
            $order->forceFill(['status' => OrderStatus::Completed])->save(); // completion itself is M9
            $award->earn($order);
            $award->earn($order); // idempotent
            $expected += $award->pointsFor($order);
        } else {
            $order = Order::query()->inRandomOrder()->firstOrFail();
            $reversed = $award->reverse($order);
            $award->reverse($order); // idempotent
            if ($reversed !== null && $reversed->wasRecentlyCreated) {
                $expected -= $award->pointsFor($order);
            }
        }

        expect(app(VerifyLedger::class)->handle())->toBe([], "seed {$seed}, step {$step}")
            ->and(Points::balance($fx['buyer']->id))->toBe($expected);
    }
})->with([5, 99]);

it('settlement conserves money: after completion, releases and reserve returns every cent is with the seller, the platform or refunded', function (int $seed): void {
    mt_srand($seed);
    (new UserLevelSeeder)->run();
    $fx = CheckoutFixture::build();
    $fx['manual']->forceFill(['manual_stock_qty' => 500])->save();
    $seller = $fx['seller']->user;
    $net = 0;
    $commission = 0;
    $paidTotal = 0;
    $refundedTotal = 0;

    foreach (range(1, 14) as $step) {
        $this->travel(2)->hours(); // stay under the orders-per-hour risk limit
        $this->actingAs($fx['buyer']);
        $quantity = mt_rand(1, 3);

        if (mt_rand(0, 3) === 0) {
            // Paid but cancelled by the seller before delivery: refunded in full.
            $this->post('/checkout', CheckoutFixture::form($fx['manual'], ['quantity' => $quantity]));
            $order = CheckoutFixture::lastOrder($fx['buyer']);
            CheckoutFixture::webhook($order, PaymentStatus::Captured);
            $paidTotal += $order->refresh()->total_amount;
            app(OrderStateMachine::class)->cancel($order, 'seller_cancel: test', ActorType::Seller, $seller);
            $refundedTotal += $order->refresh()->total_amount;
        } else {
            $order = CheckoutFixture::deliveredOrder($fx, 'manual', ['quantity' => $quantity]);
            $paidTotal += $order->total_amount;

            if (mt_rand(0, 1) === 1) {
                $this->post("/buy/orders/{$order->public_id}/confirm")->assertSessionHasNoErrors();
            } // the others complete through the confirmation timer below
        }

        if (mt_rand(0, 2) === 0) {
            $this->travel(mt_rand(1, 30))->days();
            Artisan::call('orders:process-timers');
            Artisan::call('holds:release');
        }

        expect(app(VerifyLedger::class)->handle())->toBe([], "seed {$seed}, step {$step}");
    }

    // Time passes: unconfirmed orders auto-confirm, every hold and reserve is released.
    $this->travel(100)->days();
    Artisan::call('orders:process-timers');
    $this->travel(100)->days();
    Artisan::call('holds:release');   // earnings out of the hold; reserves start their own 60 days
    $this->travel(61)->days();
    Artisan::call('holds:release');
    Artisan::call('holds:release');

    foreach (Order::query()->where('status', OrderStatus::Completed)->get() as $o) {
        $net += $o->seller_net_amount;
        $commission += $o->commission_amount;
    }

    expect(Order::query()->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])->count())->toBe(0)
        ->and(Ledger::owedBy($seller, 'SELLER_PENDING'))->toBe(0)
        ->and(Ledger::owedBy($seller, 'SELLER_RESERVE'))->toBe(0)
        ->and(Ledger::owedBy($seller, 'SELLER_AVAILABLE'))->toBe($net)
        ->and(Ledger::revenue('REV_COMMISSION'))->toBe($commission)
        ->and(Ledger::escrow())->toBe(0)
        // Everything the buyer paid and did not get back is the seller's net, our commission and our fees.
        ->and(Ledger::clearing())->toBe($paidTotal - $refundedTotal)
        ->and(Ledger::clearing())->toBe($net + $commission + Ledger::feeRevenue())
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
})->with([3, 17, 58]);
