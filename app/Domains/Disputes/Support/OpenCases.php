<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Support;

use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Payments\Models\Chargeback;

/**
 * An open dispute or chargeback on an order blocks completion and cancellation (spec 6.1 / 6.4) and keeps
 * the order's holds frozen. Dispute and chargeback actions close the case before they change the order.
 */
final class OpenCases
{
    public static function blocks(int $orderId): bool
    {
        return Dispute::query()->where('order_id', $orderId)->whereIn('status', DisputeStatus::openValues())->exists()
            || Chargeback::query()->where('order_id', $orderId)->where('status', Chargeback::OPEN)->exists();
    }
}
