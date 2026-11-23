<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\OpenConversation;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Events\ConversationUpdated;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Tests\Support\CheckoutFixture;

/*
| Live chat over Reverb (ADR-021): a new message pings the participants-only private channel; the signal carries no
| text; only participants may subscribe.
*/

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->conversation = app(OpenConversation::class)->preSale($this->fx['buyer'], $this->fx['seller']) ?? throw new RuntimeException('no chat');
});

it('broadcasts a text-free signal on the private conversation channel for every new message', function (): void {
    Event::fake([ConversationUpdated::class]);

    app(SendMessage::class)->handle($this->conversation, $this->fx['buyer'], 'Is this still available? mail me at a@b.com');

    Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $e): bool {
        $payload = json_encode(get_object_vars($e), JSON_THROW_ON_ERROR);

        return $e->conversation === $this->conversation->public_id
            && $e->broadcastOn()[0]->name === "private-conversation.{$this->conversation->public_id}"
            && $e->broadcastAs() === 'conversation.updated'
            && ! str_contains($payload, 'available')
            && ! str_contains($payload, 'a@b.com');
    });
});

it('authorises only the two participants on the conversation channel', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // Channel rules were registered at boot on the test default (null) broadcaster: register them on Reverb.
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
    $channel = "private-conversation.{$this->conversation->public_id}";

    $this->actingAs($this->fx['buyer'])->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk();
    $this->actingAs($this->fx['seller']->user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk();
    $this->actingAs(User::factory()->create())->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertForbidden();
});

it('a broadcast failure never breaks sending (Reverb down → polling fallback)', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1, // nothing listens here
    ]);

    $message = app(SendMessage::class)->handle($this->conversation, $this->fx['buyer'], 'Hello');

    expect($message->exists)->toBeTrue();
});
