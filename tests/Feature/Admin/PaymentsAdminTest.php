<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\PaymentEvent;
use App\Domains\Payments\Providers\FakePaymentProvider;
use App\Filament\Resources\Payments\Pages\ListPaymentEvents;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ListRefunds;
use App\Filament\Resources\Payments\PaymentResource;
use App\Shared\Audit\AuditLog;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Payments, provider events and refunds (docs/ADMIN_PLAN.md item 5).
 */

function postFakeWebhook(string $body): void
{
    $psp = app(FakePaymentProvider::class);
    test()->call('POST', '/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $psp->sign($body), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
}

it('re-applies an event that arrived before its payment, once, and audits it', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    $payment = CheckoutFixture::pspPayment($order);
    $realId = (string) $payment->provider_payment_id;

    // The webhook names a payment we do not know yet → recorded as unknown_payment, nothing changes.
    $payment->forceFill(['provider_payment_id' => 'not-yet-known'])->save();
    postFakeWebhook(app(FakePaymentProvider::class)->webhookBody($realId, PaymentStatus::Captured, $payment->amount, $payment->currency, 'evt-early-1'));
    $event = PaymentEvent::query()->where('provider_event_id', 'evt-early-1')->sole();
    expect($event->error)->toBe('unknown_payment')->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    $payment->forceFill(['provider_payment_id' => $realId])->save();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());

    Livewire::test(ListPaymentEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->callAction(TestAction::make('reapply')->table($event))
        ->assertActionHidden(TestAction::make('reapply')->table($event));

    expect($event->refresh()->error)->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(AuditLog::query()->where('action', 'payments.event_reapplied')->count())->toBe(1);
});

it('lists payments with a failed tab, and refunds', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $failedOrder = CheckoutFixture::lastOrder($fx['buyer']);
    CheckoutFixture::webhook($failedOrder, PaymentStatus::Failed);
    $failed = CheckoutFixture::pspPayment($failedOrder);

    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $paidOrder = CheckoutFixture::lastOrder($fx['buyer']);
    CheckoutFixture::webhook($paidOrder, PaymentStatus::Captured);
    $captured = CheckoutFixture::pspPayment($paidOrder);

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$failed, $captured])
        ->set('activeTab', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$captured]);
    Livewire::test(ListRefunds::class)->assertOk();
});

it('is open to finance and admins only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    expect(PaymentResource::canAccess())->toBeFalse();
    $this->get('/admin/payment-events')->assertForbidden();
});
