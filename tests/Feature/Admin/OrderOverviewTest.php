<?php

declare(strict_types=1);

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Shared\Support\Settings;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Order 360° page (docs/ADMIN_PLAN.md item 4).
 */

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
});

it('shows history, money, payments, deliveries, chat, journals and notes without secrets', function (): void {
    $fx = CheckoutFixture::build();
    $order = CheckoutFixture::deliveredOrder($fx, 'manual', ['secrets' => 'KEY-SUPER-SECRET-123']);
    $conversation = Conversation::query()->where('order_id', $order->id)->firstOrFail();
    app(SendMessage::class)->handle($conversation, $fx['buyer'], 'Thanks!');
    $admin = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    app(StaffNotes::class)->add($admin, 'order', $order->id, 'Buyer called about this one');
    $this->actingAs($admin);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertSee('Order '.$order->number)
        ->assertSee('paid → delivered')
        ->assertSee('Payments')
        ->assertSee('captured')
        ->assertSee('PAYMENT_CAPTURED')
        ->assertSee('Read the transcript')
        ->assertSee('Buyer called about this one')
        ->assertDontSee('KEY-SUPER-SECRET-123');
});

it('approves a payment under review from the order page', function (): void {
    $fx = CheckoutFixture::build();
    $fx['buyer']->forceFill(['created_at' => now()->subHours(2)])->save();
    app(Settings::class)->set('risk_review_new_account_amount', 1);
    app(Settings::class)->set('max_order_amount_new_buyer', 100000);
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);
    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReview);

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible(TestAction::make('approve'))
        ->callAction(TestAction::make('approve'))
        ->assertActionHidden(TestAction::make('approve'));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});
