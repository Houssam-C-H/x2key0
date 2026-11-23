<?php

declare(strict_types=1);

namespace App\Domains\Offers\Enums;

/** Publish Immediately, or keep it as Pending Publish until the seller publishes it (spec 1.8 #11). */
enum PublishMode: string
{
    case Immediate = 'immediate';
    case Pending = 'pending';
}
