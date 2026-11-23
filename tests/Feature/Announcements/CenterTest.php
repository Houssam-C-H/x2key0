<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

function announce(array $attrs = []): Announcement
{
    return Announcement::query()->create([
        'title' => ['en' => 'Maintenance', 'fr' => 'Maintenance'],
        'body' => ['en' => 'We will be back soon.', 'fr' => 'Nous revenons bientôt.'],
        'audience' => 'all',
        'category' => 'platform_service',
        'publish_at' => now()->subMinute(),
        'expires_at' => null,
        ...$attrs,
    ]);
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists notifications and announcements together, unread first in the badge count, newest first', function (): void {
    Notifier::notify($this->user->id, 'low_stock', 'merchant', ['title' => 'Alpha key', 'href' => '/seller/storage']);
    announce();

    $this->get('/announcements')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Announcements/Index')
        ->where('unread', 2)
        ->has('items', 2)
        ->where('items.0.title', 'Low stock: Alpha key')   // newest first (the announcement is a minute older)
        ->where('items.0.href', '/seller/storage')
        ->where('items.1.title', 'Maintenance'));

    // Shared bell badge on every page.
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('inbox.unread', 2));
    $this->get('/en')->assertInertia(fn (Assert $p) => $p->where('inbox.unread', 2));
});

it('translates notifications into the reader\'s language', function (): void {
    Notifier::notify($this->user->id, 'order_new', 'order', ['number' => 'X2K2611230001', 'href' => '/seller/orders/x']);

    $this->withUnencryptedCookie('locale', 'fr')->get('/announcements')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Nouvelle commande X2K2611230001'));
});

it('marks read and unread, stars, and filters All · Unread · Read · Starred', function (): void {
    $a = Notifier::notify($this->user->id, 'low_stock', 'merchant', ['title' => 'A']);
    $b = Notifier::notify($this->user->id, 'low_stock', 'merchant', ['title' => 'B']);

    $this->post("/announcements/n{$a->id}/read", ['read' => true])->assertRedirect();
    $this->post("/announcements/n{$b->id}/star")->assertRedirect();

    $ids = fn (string $filter) => collect($this->get("/announcements?filter={$filter}")->inertiaProps('items'))->pluck('id')->all();
    expect($ids('all'))->toHaveCount(2)
        ->and($ids('unread'))->toBe(["n{$b->id}"])
        ->and($ids('read'))->toBe(["n{$a->id}"])
        ->and($ids('starred'))->toBe(["n{$b->id}"]);

    $this->post("/announcements/n{$a->id}/read", ['read' => false]);
    $this->post("/announcements/n{$b->id}/star"); // toggles off
    expect($ids('unread'))->toHaveCount(2)->and($ids('starred'))->toBe([]);
});

it('marks everything read in one go', function (): void {
    Notifier::notify($this->user->id, 'low_stock', 'merchant', ['title' => 'A']);
    announce();

    $this->post('/announcements/read-all')->assertSessionHas('success');

    $this->get('/announcements')->assertInertia(fn (Assert $p) => $p->where('unread', 0));
});

it('filters by category', function (): void {
    Notifier::notify($this->user->id, 'order_new', 'order', ['number' => 'X1']);
    announce();

    $this->get('/announcements?category=order')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.category', 'order'));
});

it('shows announcements by audience, publication and expiry — sellers also see seller announcements', function (): void {
    announce(['audience' => 'all', 'title' => ['en' => 'For all']]);
    announce(['audience' => 'sellers', 'title' => ['en' => 'For sellers']]);
    announce(['audience' => 'all', 'publish_at' => now()->addDay(), 'title' => ['en' => 'Not yet']]);
    announce(['audience' => 'all', 'expires_at' => now()->subMinute(), 'title' => ['en' => 'Gone']]);

    $titles = fn () => collect($this->get('/announcements')->inertiaProps('items'))->pluck('title')->all();
    expect($titles())->toBe(['For all']);

    $seller = SellerProfile::factory()->create()->user;
    $this->actingAs($seller);
    expect($titles())->toEqualCanonicalizing(['For all', 'For sellers']);
});

it('keeps read and star state per user without a row until someone interacts', function (): void {
    $a = announce();
    expect(InboxItem::query()->count())->toBe(0);

    $this->post("/announcements/a{$a->id}/read")->assertRedirect();
    $this->post("/announcements/a{$a->id}/star");
    expect(InboxItem::query()->count())->toBe(1);

    // Another user still has it unread and unstarred.
    $this->actingAs(User::factory()->create())->get('/announcements')->assertInertia(fn (Assert $p) => $p->where('unread', 1)->where('items.0.read', false)->where('items.0.starred', false));

    // The state belongs to the first user.
    $this->actingAs($this->user)->get('/announcements')->assertInertia(fn (Assert $p) => $p->where('unread', 0)->where('items.0.read', true)->where('items.0.starred', true));
});

it('refuses other people\'s notifications, hidden announcements and malformed ids', function (): void {
    $other = Notifier::notify(User::factory()->create()->id, 'low_stock', 'merchant', ['title' => 'x']);
    $hidden = announce(['audience' => 'sellers']);

    $this->post("/announcements/n{$other->id}/read")->assertNotFound();
    $this->post("/announcements/a{$hidden->id}/read")->assertNotFound();
    $this->post('/announcements/x1/read')->assertNotFound();
    expect($other->refresh()->read_at)->toBeNull();
});

it('notifies the people an order concerns: new order, delivered, completed, funds released', function (): void {
    $fx = CheckoutFixture::build();
    $seller = $fx['seller']->user;

    $order = CheckoutFixture::deliveredOrder($fx);
    $this->actingAs($fx['buyer'])->post("/buy/orders/{$order->public_id}/confirm");
    $this->travel(15)->days();
    Artisan::call('holds:release');

    $types = fn (User $u) => InboxItem::query()->where('user_id', $u->id)->orderBy('id')->pluck('notification_type')->all();
    expect($types($seller))->toBe(['order_new', 'order_completed', 'funds_released'])
        ->and($types($fx['buyer']))->toBe(['order_delivered']);

    $this->actingAs($seller)->get('/announcements')->assertInertia(fn (Assert $p) => $p
        ->where('items.0.title', 'Earnings released: USD 9.20')
        ->where('items.1.title', 'Order '.$order->number.' completed'));
});

it('lets support staff compose announcements in the admin, and keeps finance out', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())->get('/admin/announcements')->assertOk();
    $this->get('/admin/announcements/create')->assertOk();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/announcements')->assertForbidden();
});
