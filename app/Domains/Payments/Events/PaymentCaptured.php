<?php

declare(strict_types=1);

namespace App\Domains\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A PSP or credits payment was captured (spec 7.2 PAYMENT_CAPTURED / CREDITS_SPENT, journals in M7). */
final class PaymentCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}
}
