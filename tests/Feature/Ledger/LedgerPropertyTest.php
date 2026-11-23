<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Points\Support\Points;
use Database\Seeders\PointsRuleSeeder;
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
    $paid = Order::query()->whereIn('status', [OrderStatus::Paid->value, OrderStatus::PaymentReview->value])->get()
        ->sum(fn (Order $o): int => $o->subtotal_amount - $o->discount_seller_amount);
    $pending = (int) Order::query()->where('status', OrderStatus::PendingPayment)->sum('paid_credits_amount');
    $late = (int) LedgerJournal::query()->where('type', JournalType::LateCapture)->with('postings')->get()
        ->sum(fn (LedgerJournal $j): int => (int) $j->postings->where('amount', '>', 0)->sum('amount'));

    return $paid + $pending + $late;
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
            ->and(Ledger::clearing())->toBe((int) Payment::query()->where('provider', 'fake')->where('status', PaymentStatus::Captured)->sum('amount'))
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
