<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Listeners;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Events\OrderCompleted;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Actions\RecomputeLevels;

/** On completion (spec 6.1 "invite comment"): ask the buyer for a comment and update the buyer's level. */
final class ReviewListeners
{
    public function __construct(private readonly RecomputeLevels $levels) {}

    public function completed(OrderCompleted $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order === null) {
            return;
        }

        Notifier::notify($order->buyer_id, 'comment_invite', 'todo', ['number' => $order->number, 'href' => '/buy/comments']);

        $buyer = User::query()->find($order->buyer_id);
        if ($buyer !== null) {
            $this->levels->user($buyer);
        }
    }
}
