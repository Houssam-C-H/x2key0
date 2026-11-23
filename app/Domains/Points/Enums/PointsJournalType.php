<?php

declare(strict_types=1);

namespace App\Domains\Points\Enums;

enum PointsJournalType: string
{
    case EarnOrder = 'earn_order';
    case EarnTask = 'earn_task';
    case RedeemCoupon = 'redeem_coupon';
    case Reverse = 'reverse';
    case Expire = 'expire';
    case Adjust = 'adjust';
}
