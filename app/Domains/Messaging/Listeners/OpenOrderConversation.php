<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Listeners;

use App\Domains\Messaging\Actions\OpenConversation;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Models\Order;

/** The order conversation exists from `paid` on (spec 12.1). */
final class OpenOrderConversation
{
    public function __construct(private readonly OpenConversation $open) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order !== null) {
            $this->open->forOrder($order);
        }
    }
}
