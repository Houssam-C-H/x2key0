<?php

declare(strict_types=1);

namespace App\Domains\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Order completed and settled (spec 6.1): seller earnings are held, the buyer's points are earned. */
final class OrderCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId) {}
}
