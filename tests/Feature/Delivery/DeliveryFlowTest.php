<?php

declare(strict_types=1);

use App\Domains\Delivery\Models\AutoDeliveryLog;
use App\Domains\Delivery\Models\SecretReveal;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->seller = $this->fx['seller']->user;
    $this->pay = function (string $offer, array $overrides = []) {
        $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx[$offer], $overrides));
        $order = CheckoutFixture::lastOrder($this->buyer);
        CheckoutFixture::webhook($order, PaymentStatus::Captured);

        return $order->refresh();
    };
    $this->confirmPassword = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('auto-delivers from Storage the moment payment is captured: items assigned once, logged, order delivered', function (): void {
    $order = ($this->pay)('auto', ['quantity' => 2]);

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->item->delivered_qty)->toBe(2)
        ->and($order->confirm_due_at?->isSameDay(now()->addHours(72)))->toBeTrue()
        ->and(OrderTimer::query()->where('order_id', $order->id)->where('type', OrderTimerType::ConfirmDue)->exists())->toBeTrue()
        ->and(StorageItem::query()->where('assigned_order_item_id', $order->item->id)->where('status', StorageItemStatus::Assigned)->count())->toBe(2)
        ->and(AutoDeliveryLog::query()->where('result', 'success')->count())->toBe(2)
        ->and($this->fx['group']->refresh()->available_count_cached)->toBe(1)
        ->and($order->history()->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->all())->toBe(['pending_payment', 'paid', 'delivered']);
});

it('lets the buyer reveal what was delivered after a recent password check, and logs every reveal', function (): void {
    $order = ($this->pay)('auto', ['quantity' => 2]);

    // Without a recent password confirmation the reveal is refused.
    $this->actingAs($this->buyer)->postJson("/buy/orders/{$order->public_id}/reveal")->assertStatus(423);

    ($this->confirmPassword)();
    $response = $this->postJson("/buy/orders/{$order->public_id}/reveal")->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $values = collect($response->json('secrets'))->pluck('value')->sort()->values()->all();
    expect($values)->toBe(['KEY-AAA-1', 'KEY-BBB-2'])
        ->and(SecretReveal::query()->where('user_id', $this->buyer->id)->count())->toBe(2);

    // Someone else cannot, and the seller cannot read delivered keys back either.
    $this->actingAs($this->seller)->postJson("/buy/orders/{$order->public_id}/reveal")->assertNotFound();
});

it('the order page and the seller page never carry the delivered secrets', function (): void {
    $order = ($this->pay)('auto');

    $buyerPage = $this->actingAs($this->buyer)->get("/buy/orders/{$order->public_id}")->assertOk();
    $sellerPage = $this->actingAs($this->seller)->get("/seller/orders/{$order->public_id}")->assertOk();

    expect($buyerPage->getContent().$sellerPage->getContent())->not->toContain('KEY-AAA-1')->not->toContain('KEY-BBB-2')->not->toContain('KEY-CCC-3');
    $buyerPage->assertInertia(fn (Assert $p) => $p->where('can.reveal', true)->where('order.deliveries.0.secrets', 1));
});

it('logs a failed auto delivery and leaves the order for the seller to deliver by hand', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['auto']));
    $order = CheckoutFixture::lastOrder($this->buyer);
    StorageItem::query()->where('reserved_for_order_item_id', $order->item->id)->delete(); // simulate lost stock

    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(AutoDeliveryLog::query()->where('result', 'failed')->count())->toBe(1);

    $this->actingAs($this->seller)->get("/seller/orders/{$order->public_id}")->assertInertia(fn (Assert $p) => $p->where('can.deliver', true));
});

it('shows the seller the buyer inputs only once the order is paid, and keeps unpaid orders out of Sold Orders', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $order = CheckoutFixture::lastOrder($this->buyer);

    $this->actingAs($this->seller)->get('/seller/orders')->assertInertia(fn (Assert $p) => $p->where('orders.total', 0));
    $this->get("/seller/orders/{$order->public_id}")->assertNotFound();

    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    $this->get("/seller/orders/{$order->public_id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('order.buyerInputs.0.label', 'Player ID')
        ->where('order.buyerInputs.0.value', '12345678')
        ->where('order.substatus', 'new')
        ->where('can.accept', true));
});

it('moves the seller sub-status new → restocking → in trading and writes the timeline', function (): void {
    $order = ($this->pay)('manual');
    $this->actingAs($this->seller);

    $this->post("/seller/orders/{$order->public_id}/substatus", ['substatus' => 'restocking'])->assertSessionHasNoErrors();
    expect($order->refresh()->seller_substatus)->toBe(SellerSubstatus::Restocking)->and($order->accepted_at)->not->toBeNull();

    $this->post("/seller/orders/{$order->public_id}/substatus", ['substatus' => 'in_trading'])->assertSessionHasNoErrors();
    expect($order->refresh()->seller_substatus)->toBe(SellerSubstatus::InTrading)
        ->and(OrderTimer::query()->where('order_id', $order->id)->where('type', OrderTimerType::AcceptDue)->value('processed_at'))->not->toBeNull()
        ->and($order->history()->whereNotNull('to_substatus')->count())->toBe(3); // new (paid), restocking, in_trading

    $this->post("/seller/orders/{$order->public_id}/substatus", ['substatus' => 'restocking'])->assertSessionHasErrors('order');
});

it('delivers a manual top-up with a note and a screenshot kept on the private disk', function (): void {
    Storage::fake('local');
    $order = ($this->pay)('manual');

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 1])->assertSessionHasErrors('note');
    $this->post("/seller/orders/{$order->public_id}/deliver", [
        'quantity' => 1,
        'note' => 'Top-up transaction TX-889',
        'evidence' => UploadedFile::fake()->image('proof.png', 400, 300),
    ])->assertSessionHasNoErrors();

    $order->refresh();
    $evidence = DB::table('delivery_evidence')->first();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($evidence)->not->toBeNull()
        ->and($evidence->sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($order->confirm_due_at)->not->toBeNull();
    Storage::disk('local')->assertExists($evidence->file_path);

    // Only the buyer and the seller of the order can open the proof.
    $this->get("/seller/orders/{$order->public_id}/evidence/{$evidence->id}")->assertOk();
    $this->actingAs($this->buyer)->get("/buy/orders/{$order->public_id}/evidence/{$evidence->id}")->assertOk();
    $this->actingAs(User::factory()->create())->get("/buy/orders/{$order->public_id}/evidence/{$evidence->id}")->assertNotFound();
});

it('delivers codes typed by the seller sealed, one per unit, and the buyer reveals them', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::Code])->save();
    $order = ($this->pay)('manual', ['quantity' => 2]);

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 2, 'secrets' => 'CODE-ONE'])->assertSessionHasErrors('secrets');
    $this->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 2, 'secrets' => "CODE-ONE\nCODE-TWO"])->assertSessionHasNoErrors();

    expect(DB::table('delivery_secrets')->get()->pluck('ciphertext')->implode(' '))->not->toContain('CODE-ONE')
        ->and($order->refresh()->status)->toBe(OrderStatus::Delivered);

    ($this->confirmPassword)();
    $values = collect($this->actingAs($this->buyer)->postJson("/buy/orders/{$order->public_id}/reveal")->json('secrets'))->pluck('value')->sort()->values()->all();
    expect($values)->toBe(['CODE-ONE', 'CODE-TWO']);
});

it('partial delivery goes to delivering; the buyer may stop and refund the rest after the deadline', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::Code])->save();
    $order = ($this->pay)('manual', ['quantity' => 3]); // 3000 + 90 fee

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 1, 'secrets' => 'CODE-1'])->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Delivering)->and($order->item->delivered_qty)->toBe(1);

    // Before the deadline the buyer cannot stop it.
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/cancel-remainder")->assertSessionHasErrors('order');

    $this->travel(31)->minutes();
    $this->post("/buy/orders/{$order->public_id}/cancel-remainder")->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->item->refunded_qty)->toBe(2)
        ->and($order->refunded_psp_amount)->toBe(2060) // 2 × $10.00 + 2/3 of the $0.90 fee, floored: 60¢
        ->and(Ledger::escrow())->toBe(1000)            // the delivered unit stays in escrow
        ->and(Ledger::clearing())->toBe(3090 - 2060);
});

it('delivers service orders through progress steps; 100 % delivers the order', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::Service])->save();
    $order = ($this->pay)('manual');
    $this->actingAs($this->seller);

    $this->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 1, 'note' => 'x'])->assertSessionHasErrors('order');
    $this->post("/seller/orders/{$order->public_id}/milestones", ['title' => 'Placement matches', 'percent' => 40])->assertSessionHasNoErrors();
    expect($order->refresh()->seller_substatus)->toBe(SellerSubstatus::InTrading)->and($order->status)->toBe(OrderStatus::Paid);

    $this->post("/seller/orders/{$order->public_id}/milestones", ['title' => 'Back', 'percent' => 30])->assertSessionHasErrors('percent');
    $this->post("/seller/orders/{$order->public_id}/milestones", ['title' => 'Done', 'percent' => 100])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->confirm_due_at?->isSameDay(now()->addHours(168)))->toBeTrue(); // services get the longer window
});

it('account transfers start the recovery-protection timer', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::AccountTransfer, 'account_recovery_protection_days' => 30])->save();
    $order = ($this->pay)('manual');

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 1, 'secrets' => 'hero|pw1|hero@example.com'])->assertSessionHasNoErrors();

    expect(OrderTimer::query()->where('order_id', $order->id)->where('type', OrderTimerType::RecoveryProtectionEnd)->value('due_at'))->not->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Delivered);

    ($this->confirmPassword)();
    $account = $this->actingAs($this->buyer)->postJson("/buy/orders/{$order->public_id}/reveal")->json('secrets.0.account');
    expect($account['login'])->toBe('hero')->and($account['password'])->toBe('pw1');
});

it('keeps seller notes and acquisition cost private to the seller', function (): void {
    $order = ($this->pay)('manual');

    $this->actingAs($this->seller)->put("/seller/orders/{$order->public_id}/notes", ['remark' => 'Buyer asked for night delivery', 'acquisition_cost' => '6.40'])->assertSessionHasNoErrors();

    expect($order->refresh()->seller_internal_remark)->toBe('Buyer asked for night delivery')->and($order->seller_acquisition_cost_amount)->toBe(640);
    $this->get('/seller/orders?remark=night')->assertInertia(fn (Assert $p) => $p->where('orders.total', 1)->where('orders.items.0.acquisitionCost', 640));
    expect($this->actingAs($this->buyer)->get("/buy/orders/{$order->public_id}")->getContent())->not->toContain('night delivery');
});

it('filters and counts Sold Orders by tab', function (): void {
    ($this->pay)('manual');
    $delivered = ($this->pay)('auto');

    $this->actingAs($this->seller)->get('/seller/orders')->assertInertia(fn (Assert $p) => $p
        ->where('counts.all', 2)->where('counts.pending_delivery', 1)->where('counts.pending_feedback', 1)->where('counts.cancelled', 0));
    $this->get('/seller/orders?tab=pending_feedback')->assertInertia(fn (Assert $p) => $p->has('orders.items', 1)->where('orders.items.0.number', $delivered->number));
    $this->get('/seller/orders?number='.$delivered->number)->assertInertia(fn (Assert $p) => $p->where('orders.total', 1));
});
