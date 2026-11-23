<?php

declare(strict_types=1);

namespace App\Domains\Payments\Jobs;

use App\Domains\Payments\Actions\ApplyPaymentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Applies one stored payment event (spec 9.3 #4). Safe to run twice: ApplyPaymentEvent is idempotent. */
final class ProcessPaymentEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('payments');
    }

    public function handle(ApplyPaymentEvent $apply): void
    {
        $apply->handle($this->eventId);
    }
}
