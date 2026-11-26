<?php

declare(strict_types=1);

use App\Domains\Announcements\Jobs\SendWebPush;
use App\Domains\Announcements\Models\PushSubscription;
use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Installable app + web push (ADR-037): device subscriptions, shared public key, push for important notifications.
 */

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->sub = ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'authSecret']];
});

it('stores, updates and removes this device\'s subscription', function (): void {
    $this->postJson('/push/subscription', $this->sub)->assertOk();
    $this->postJson('/push/subscription', $this->sub)->assertOk();
    expect(PushSubscription::query()->where('user_id', $this->user->id)->count())->toBe(1);

    $this->postJson('/push/subscription', ['endpoint' => 'http://insecure.test', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertUnprocessable();

    $this->deleteJson('/push/subscription', ['endpoint' => $this->sub['endpoint']])->assertOk();
    expect(PushSubscription::query()->count())->toBe(0);
});

it('shares the public key and pushes important notifications only when push is configured', function (): void {
    Bus::fake([SendWebPush::class]);

    config(['x2key.push.public_key' => null, 'x2key.push.private_key' => null]);
    $this->get('/settings/account')->assertInertia(fn (Assert $page) => $page->where('pushKey', null));
    Notifier::notify($this->user->id, 'order_delivered', 'order', ['number' => 'X2K-1', 'href' => '/buy/orders/abc']);
    Bus::assertNotDispatched(SendWebPush::class);

    config(['x2key.push.public_key' => 'BPUBLIC', 'x2key.push.private_key' => 'private']);
    $this->get('/settings/account')->assertInertia(fn (Assert $page) => $page->where('pushKey', 'BPUBLIC'));
    Notifier::notify($this->user->id, 'order_delivered', 'order', ['number' => 'X2K-2', 'href' => '/buy/orders/def']);
    Notifier::notify($this->user->id, 'comment_received', 'order', ['number' => 'X2K-2']);

    Bus::assertDispatchedTimes(SendWebPush::class, 1);
    Bus::assertDispatched(SendWebPush::class, fn (SendWebPush $job): bool => $job->message['title'] === 'Order X2K-2 delivered' && $job->message['url'] === '/buy/orders/def');
});
