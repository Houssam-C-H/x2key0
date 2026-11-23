<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use Illuminate\Support\Facades\DB;

/**
 * Apply to sell in a game×category (spec 6.9). A refused application can be re-submitted; pending and
 * passed ones cannot be duplicated. Seller levels with `auto_approve` pass immediately (no reviewer).
 */
final class ApplyForService
{
    public function handle(SellerProfile $profile, int $gameCategoryId): SellerServiceApplication
    {
        if ($profile->status !== SellerStatus::Active && $profile->status !== SellerStatus::Vacation) {
            throw SellerException::because('seller.error.not_active');
        }

        $exists = GameCategory::query()->visible()->whereKey($gameCategoryId)->exists();
        if (! $exists) {
            throw SellerException::because('seller.services.error.unknown');
        }

        return DB::transaction(function () use ($profile, $gameCategoryId): SellerServiceApplication {
            $application = SellerServiceApplication::query()
                ->where('seller_profile_id', $profile->id)
                ->where('game_category_id', $gameCategoryId)
                ->lockForUpdate()
                ->first();

            if ($application !== null && $application->status !== ServiceApplicationStatus::Refused) {
                throw SellerException::because($application->status === ServiceApplicationStatus::Passed
                    ? 'seller.services.error.passed'
                    : 'seller.services.error.pending');
            }

            $application ??= new SellerServiceApplication(['seller_profile_id' => $profile->id, 'game_category_id' => $gameCategoryId]);

            $autoApprove = $profile->level?->auto_approve === true;

            $application->status = $autoApprove ? ServiceApplicationStatus::Passed : ServiceApplicationStatus::Pending;
            $application->reject_reason = null;
            $application->audited_at = $autoApprove ? now() : null;
            $application->audited_by = null;
            $application->save();

            return $application->refresh();
        });
    }
}
