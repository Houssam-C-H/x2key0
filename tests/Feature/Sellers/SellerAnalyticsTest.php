<?php

declare(strict_types=1);

use App\Domains\Offers\Models\Offer;
use App\Domains\Payments\Enums\PaymentStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

/*
 * Seller Center › Analytics: totals, daily series, top offers, price against competitors.
 */

it('reports paid orders, sales, net and price position against a cheaper competitor', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer']);
    $this->post('/checkout', CheckoutFixture::form($fx['manual']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    CheckoutFixture::webhook($order, PaymentStatus::Captured)->assertOk();

    $manual = $fx['manual']->refresh();
    $competitor = Offer::factory()->create([
        'game_category_id' => $manual->game_category_id,
        'product_id' => $manual->product_id,
        'unit_price_amount' => max(1, $manual->unit_price_amount - 100),
    ]);

    $this->actingAs($fx['seller']->user)->get('/seller/analytics?days=7')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Analytics')
        ->where('analytics.days', 7)
        ->has('analytics.series', 7)
        ->where('analytics.totals.orders', 1)
        ->where('analytics.totals.sales', $order->refresh()->total_amount)
        ->where('analytics.totals.net', $order->seller_net_amount)
        ->where('analytics.topOffers.0.orders', 1)
        ->where('analytics.pricing', fn ($rows): bool => collect($rows)->contains(fn (array $r): bool => $r['title'] === $manual->title
            && $r['lowestOther'] === $competitor->unit_price_amount && $r['position'] === 'above')));
});
