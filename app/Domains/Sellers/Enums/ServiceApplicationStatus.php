<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/**
 * Seller service application (spec 6.9): pending → passed | refused (reason) → reapply.
 */
enum ServiceApplicationStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Refused = 'refused';
}
