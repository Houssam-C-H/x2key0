<?php

declare(strict_types=1);

namespace App\Domains\Offers\Enums;

/** `auto` = delivered from a Storage group (M8); `manual` = the seller delivers and stock is a number. */
enum Fulfillment: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
