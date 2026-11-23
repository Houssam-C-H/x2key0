<?php

declare(strict_types=1);

namespace App\Domains\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Order cancelled before delivery (spec 6.1); stock and credits are already back. */
final class OrderCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId, public readonly string $reason) {}
}
