<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Coupons\Models\CouponClaim;
use Illuminate\Console\Command;

/** `coupons:expire-claims` (hourly, spec Part 19): available claims past their expiry become expired (idempotent). */
final class CouponsExpireClaims extends Command
{
    protected $signature = 'coupons:expire-claims';

    protected $description = 'Mark unused coupon claims past their expiry as expired';

    public function handle(): int
    {
        $count = CouponClaim::query()->where('status', CouponClaim::AVAILABLE)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['status' => CouponClaim::EXPIRED, 'updated_at' => now()]);

        $this->info("Expired {$count} claim(s).");

        return self::SUCCESS;
    }
}
