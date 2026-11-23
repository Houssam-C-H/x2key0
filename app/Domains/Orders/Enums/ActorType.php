<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/** Who caused an order status change (order_status_history.actor_type). */
enum ActorType: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Staff = 'staff';
    case System = 'system';
}
