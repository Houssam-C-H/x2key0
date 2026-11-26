<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Facades\Filament;
use Tests\Support\CheckoutFixture;

/*
 * Global search from the admin top bar (docs/ADMIN_PLAN.md item 16).
 */

it('finds an order by number, a store by name and an offer by title from anywhere', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $orders = OrderResource::getGlobalSearchResults($order->number);
    expect($orders)->toHaveCount(1)
        ->and($orders->first()?->title)->toBe('Order '.$order->number)
        ->and($orders->first()?->url)->toBe(OrderResource::getUrl('view', ['record' => $order]));

    expect(SellerProfileResource::getGlobalSearchResults($fx['seller']->store_name)->first()?->url)->toBe(SellerProfileResource::getUrl('view', ['record' => $fx['seller']]))
        ->and(OfferResource::getGlobalSearchResults($fx['manual']->title)->count())->toBeGreaterThanOrEqual(1)
        ->and(PaymentResource::getGlobalSearchResults(CheckoutFixture::pspPayment($order)->public_id)->first()?->url)->toBe(OrderResource::getUrl('view', ['record' => $order]));
});

it('makes users, orders, offers, tickets, disputes, stores and payments searchable', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    foreach ([OrderResource::class, OfferResource::class, TicketResource::class, DisputeResource::class, SellerProfileResource::class, PaymentResource::class] as $resource) {
        expect($resource::canGloballySearch())->toBeTrue($resource);
    }
});
