<?php

declare(strict_types=1);

namespace App\Domains\Violations\Actions;

use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Exceptions\ViolationException;
use App\Domains\Violations\Models\Violation;

/**
 * `violations:process` (hourly, idempotent): open violations whose appeal window ended are upheld (spec 6.7),
 * and active-point totals are refreshed for stores whose points expired.
 */
final class ProcessViolations
{
    public function __construct(private readonly DecideViolation $decide) {}

    /**
     * @return array{upheld: int, refreshed: int}
     */
    public function handle(): array
    {
        $upheld = 0;

        Violation::query()->where('status', ViolationStatus::Open->value)->where('appeal_due_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($violations) use (&$upheld): void {
                foreach ($violations as $violation) {
                    try {
                        $this->decide->uphold($violation, null, 'appeal_window_ended');
                        $upheld++;
                    } catch (ViolationException) {
                        // decided concurrently
                    }
                }
            });

        $refreshed = 0;
        $stale = SellerStats::query()->where('violation_points', '>', 0)->pluck('seller_profile_id');
        foreach ($stale as $sellerId) {
            $points = (int) Violation::query()->active()->where('seller_profile_id', $sellerId)->sum('points');
            $refreshed += SellerStats::query()->where('seller_profile_id', $sellerId)->where('violation_points', '!=', $points)->update(['violation_points' => $points]);
        }

        return ['upheld' => $upheld, 'refreshed' => $refreshed];
    }
}
