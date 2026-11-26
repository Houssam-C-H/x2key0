<?php

declare(strict_types=1);

namespace App\Shared\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "A staff queue changed" on the staff-only channel (docs/ADMIN_PLAN.md item 11). No content: the admin re-reads
 * its counters (queue counter in the top bar, work-queue widget).
 */
final class StaffQueuesChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff')];
    }

    public function broadcastAs(): string
    {
        return 'queues.changed';
    }
}
