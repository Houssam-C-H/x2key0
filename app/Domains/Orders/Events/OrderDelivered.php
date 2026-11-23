<?php

declare(strict_types=1);

namespace App\Domains\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Order reached `delivered` (all units delivered or refunded): the buyer is asked to confirm. */
final class OrderDelivered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId) {}
}
