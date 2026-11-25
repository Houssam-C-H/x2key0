<?php

declare(strict_types=1);

use App\Domains\Payments\Enums\PaymentStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

/*
 * Buyer protection page and the "Get help with an order" entry to claims.
 */

it('shows the buyer protection page with the real confirmation window', function (): void {
    $this->get('/en/buyer-protection')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/BuyerProtection')
        ->where('confirmationHours', 72)
        ->where('seo.title', fn ($title): bool => str_contains((string) $title, 'Buyer protection')));
});

it('lists the buyer\'s paid orders with an Open a claim entry', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer']);
    $this->post('/checkout', CheckoutFixture::form($fx['manual']));
    $paid = CheckoutFixture::lastOrder($fx['buyer']);
    CheckoutFixture::webhook($paid, PaymentStatus::Captured)->assertOk();
    $this->post('/checkout', CheckoutFixture::form($fx['manual'])); // unpaid: not listed

    $this->get('/buy/claim')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Buy/Claim')
        ->has('orders', 1)
        ->where('orders.0.number', $paid->number)
        ->where('orders.0.claimable', true)
        ->where('orders.0.openCase', false));
});
