<?php

declare(strict_types=1);

use App\Domains\Announcements\Mail\NotificationMail;
use App\Domains\Announcements\Models\InboxItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CheckoutFixture;

/*
 * An unpaid order that times out becomes a friendly reminder pointing back to the offer, e-mailed at most once a day.
 */

beforeEach(function (): void {
    Mail::fake();
    $this->fx = CheckoutFixture::build();
    $this->actingAs($this->fx['buyer']);
    $this->checkout = function (): mixed {
        $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));

        return CheckoutFixture::lastOrder($this->fx['buyer']);
    };
});

it('reminds the buyer with a link back to the still-live offer instead of a cancellation notice', function (): void {
    $order = ($this->checkout)();
    $this->travel(31)->minutes();
    Artisan::call('orders:expire-unpaid');

    $item = InboxItem::query()->where('user_id', $this->fx['buyer']->id)->latest('id')->firstOrFail();
    expect($item->notification_type)->toBe('checkout_abandoned')
        ->and($item->data['number'])->toBe($order->number)
        ->and($item->data['href'])->toBe($this->fx['manual']->path('en'))
        ->and(InboxItem::query()->where('user_id', $this->fx['buyer']->id)->where('notification_type', 'order_cancelled')->exists())->toBeFalse();

    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m): bool => $m->type === 'checkout_abandoned');
});

it('e-mails the reminder at most once a day, while the inbox gets every one', function (): void {
    ($this->checkout)();
    ($this->checkout)();
    $this->travel(31)->minutes();
    Artisan::call('orders:expire-unpaid');

    expect(InboxItem::query()->where('user_id', $this->fx['buyer']->id)->where('notification_type', 'checkout_abandoned')->count())->toBe(2);
    Mail::assertQueued(NotificationMail::class, 1);

    $this->travel(25)->hours();
    ($this->checkout)();
    $this->travel(31)->minutes();
    Artisan::call('orders:expire-unpaid');
    Mail::assertQueued(NotificationMail::class, 2);
});
