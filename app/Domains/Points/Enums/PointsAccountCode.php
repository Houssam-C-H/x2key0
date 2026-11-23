<?php

declare(strict_types=1);

namespace App\Domains\Points\Enums;

/**
 * Points accounts (spec 5.13). Points are not money (CLAUDE.md): a separate, zero-sum ledger where USER(u)
 * holds the user's points (positive = owned) and ISSUED / REDEEMED / EXPIRED are the platform's counterparts.
 */
enum PointsAccountCode: string
{
    case User = 'USER';
    case Issued = 'ISSUED';
    case Redeemed = 'REDEEMED';
    case Expired = 'EXPIRED';
}
