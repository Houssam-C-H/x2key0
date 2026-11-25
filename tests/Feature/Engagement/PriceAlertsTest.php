<?php

declare(strict_types=1);

use App\Domains\Announcements\Mail\NotificationMail;
use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Engagement\Models\PriceAlert;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;

/*
 * Price alerts: set on a product or a game×category, fire once when the lowest price reaches the target,
 * re-arm when it goes back above.
 */

beforeEach(function (): void {
    Mail::fake();
    $this->fx = CatalogFixture::build();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->product = $this->fx['products']['p3'];
});

it('fires once when a product reaches the target, then re-arms above it', function (): void {
    $this->product->forceFill(['lowest_price_amount_cached' => 1500])->save();
    $this->post('/price-alerts', ['type' => 'product', 'id' => $this->product->public_id, 'target' => '12.00'])->assertSessionHasNoErrors();

    $this->get($this->product->path('en'))->assertInertia(fn (Assert $page) => $page->where('priceAlert.target', 1200)->where('priceAlert.lowest', 1500));

    Artisan::call('alerts:prices');
    expect(InboxItem::query()->where('notification_type', 'price_drop')->count())->toBe(0);

    $this->product->forceFill(['lowest_price_amount_cached' => 1150])->save();
    Artisan::call('alerts:prices');
    Artisan::call('alerts:prices');
    expect(InboxItem::query()->where('notification_type', 'price_drop')->count())->toBe(1);
    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m): bool => $m->type === 'price_drop');

    $this->product->forceFill(['lowest_price_amount_cached' => 1600])->save();
    Artisan::call('alerts:prices');
    expect(PriceAlert::query()->value('notified_at'))->toBeNull();

    $this->product->forceFill(['lowest_price_amount_cached' => 1000])->save();
    Artisan::call('alerts:prices');
    expect(InboxItem::query()->where('notification_type', 'price_drop')->count())->toBe(2);
});

it('watches the lowest live offer of a game category', function (): void {
    $gc = $this->fx['gameCategory'];
    Offer::factory()->create(['game_category_id' => $gc->id, 'unit_price_amount' => 900]);
    $this->post('/price-alerts', ['type' => 'category', 'id' => (string) $gc->id, 'target' => '10'])->assertSessionHasNoErrors();

    $lowest = Offer::query()->live()->where('offers.game_category_id', $gc->id)->min('unit_price_amount');
    Artisan::call('alerts:prices');

    expect((int) $lowest)->toBeLessThanOrEqual(900)
        ->and(InboxItem::query()->where('notification_type', 'price_drop')->value('data')['price'])->toBe('USD '.number_format((int) $lowest / 100, 2, '.', ''));
});

it('validates targets and lists and removes alerts', function (): void {
    $this->post('/price-alerts', ['type' => 'product', 'id' => 'nope', 'target' => '5'])->assertSessionHasErrors('target');
    $this->post('/price-alerts', ['type' => 'product', 'id' => $this->product->public_id, 'target' => '0'])->assertSessionHasErrors('target');
    $this->post('/price-alerts', ['type' => 'product', 'id' => $this->product->public_id, 'target' => '5.5'])->assertSessionHasNoErrors();

    $this->get('/buy/price-alerts')->assertInertia(fn (Assert $page) => $page->component('Buy/PriceAlerts')->has('alerts', 1)->where('alerts.0.target', 550));

    $id = PriceAlert::query()->value('id');
    $other = User::factory()->create();
    $this->actingAs($other)->delete("/price-alerts/{$id}");
    expect(PriceAlert::query()->count())->toBe(1);
    $this->actingAs($this->user)->delete("/price-alerts/{$id}");
    expect(PriceAlert::query()->count())->toBe(0);
});
