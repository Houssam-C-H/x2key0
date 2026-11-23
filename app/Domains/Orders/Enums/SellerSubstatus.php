<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/** Seller-facing sub-status while an order is `paid` / `delivering` (spec 5.6 / 6.1, OPEN_QUESTIONS #4). */
enum SellerSubstatus: string
{
    case New = 'new';
    case Restocking = 'restocking';
    case InTrading = 'in_trading';
    case Issue = 'issue';
}
