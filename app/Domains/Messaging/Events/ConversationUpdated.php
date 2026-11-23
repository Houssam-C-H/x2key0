<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live chat signal (spec 12.1, ADR-021): "this conversation changed". It carries no message text — each
 * participant re-fetches the thread over HTTP, so redaction stays per reader. Broadcast right away (no queue)
 * after the message is committed (Message::created hook in AppServiceProvider), on the participants-only channel.
 */
final class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public readonly string $conversation, public readonly int $messageId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversation}")];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }
}
