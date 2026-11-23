<?php

declare(strict_types=1);

namespace App\Domains\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Order reached `paid` (spec 6.1). M7 posts PAYMENT_CAPTURED from it; M8 starts auto delivery. */
final class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId) {}
}
