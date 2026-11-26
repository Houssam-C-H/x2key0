<?php

declare(strict_types=1);

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\OpenConversation;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Tickets\Actions\TicketDesk;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Filament\Livewire\QueueCounter;
use App\Http\Middleware\SecurityHeaders;
use App\Shared\Realtime\StaffQueuesChanged;
use App\Shared\Realtime\UserSignal;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Live bell, chat badge and staff queue counters (docs/ADMIN_PLAN.md item 11).
 */

it('signals the bell on the user channel without content when a notification arrives', function (): void {
    Event::fake([UserSignal::class]);
    $user = User::factory()->create();

    Notifier::notify($user->id, 'ticket_reply', 'ticket', ['subject' => 'Secret subject']);

    Event::assertDispatched(UserSignal::class, fn (UserSignal $e): bool => $e->userId === $user->id
        && $e->kind === 'inbox'
        && $e->broadcastOn()[0]->name === "private-user.{$user->id}"
        && $e->broadcastAs() === 'user.signal'
        && ! str_contains((string) json_encode($e->broadcastWith()), 'Secret'));
});

it('signals the chat badge of the other participant only', function (): void {
    $fx = CheckoutFixture::build();
    $conversation = app(OpenConversation::class)->preSale($fx['buyer'], $fx['seller']) ?? throw new RuntimeException('no chat');
    Event::fake([UserSignal::class]);

    app(SendMessage::class)->handle($conversation, $fx['buyer'], 'Hello there');

    Event::assertDispatched(UserSignal::class, fn (UserSignal $e): bool => $e->userId === $fx['seller']->user_id && $e->kind === 'chat');
    Event::assertNotDispatched(UserSignal::class, fn (UserSignal $e): bool => $e->userId === $fx['buyer']->id && $e->kind === 'chat');
});

it('signals staff queues when something enters a queue', function (): void {
    Event::fake([StaffQueuesChanged::class]);
    app(TicketDesk::class)->open(User::factory()->create(), ['topic' => TicketTopic::cases()[0], 'subject' => 'Cannot log in', 'body' => 'Please help me with my account access.', 'order_id' => null, 'form_data' => []]);

    Event::assertDispatched(StaffQueuesChanged::class, fn (StaffQueuesChanged $e): bool => $e->broadcastOn()[0]->name === 'private-staff');
});

it('lets only staff subscribe to the staff channel', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');

    $this->actingAs(User::factory()->staff(StaffRole::Support)->create())->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-staff'])->assertOk();
    $this->actingAs(User::factory()->create())->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-staff'])->assertForbidden();
});

it('shows the open work count in the admin top bar and allows the websocket in the admin CSP', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    Livewire::test(QueueCounter::class)->assertOk()->assertSeeHtml('data-queue-counter')->dispatch('staff-queues-changed')->assertOk();

    config(['x2key.realtime.public_host' => 'ws.example.test', 'x2key.realtime.public_port' => '443']);
    expect(SecurityHeaders::adminPolicy())->toContain('wss://ws.example.test:443');
});
