<?php

declare(strict_types=1);

namespace App\Shared\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Your counters changed" on the user's private channel (docs/ADMIN_PLAN.md item 11): `inbox` (bell) or `chat`
 * (chat badge). It carries no content; the browser reloads the shared counters over HTTP.
 */
final class UserSignal implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public const array KINDS = ['inbox', 'chat'];

    public function __construct(public readonly int $userId, public readonly string $kind) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'user.signal';
    }

    /**
     * @return array{kind: string}
     */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
