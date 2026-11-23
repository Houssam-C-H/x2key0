<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A hold ended and its money moved on (settlement/insurance → available, reserve → available). */
final class HoldReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $holdId) {}
}
