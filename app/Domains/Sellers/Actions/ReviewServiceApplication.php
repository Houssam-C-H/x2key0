<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerServiceApplication;

/**
 * Staff decision on a pending service application (spec 6.9). Audited via the model's Auditable trait.
 */
final class ReviewServiceApplication
{
    public function pass(SellerServiceApplication $application, User $reviewer): SellerServiceApplication
    {
        return $this->decide($application, $reviewer, ServiceApplicationStatus::Passed, null);
    }

    public function refuse(SellerServiceApplication $application, User $reviewer, string $reason): SellerServiceApplication
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SellerException::because('seller.services.error.reason_required');
        }

        return $this->decide($application, $reviewer, ServiceApplicationStatus::Refused, mb_substr($reason, 0, 500));
    }

    private function decide(SellerServiceApplication $application, User $reviewer, ServiceApplicationStatus $to, ?string $reason): SellerServiceApplication
    {
        if ($application->status !== ServiceApplicationStatus::Pending) {
            throw SellerException::because('seller.services.error.not_pending');
        }

        if ($application->profile->user_id === $reviewer->id) {
            throw SellerException::because('seller.services.error.self_review');
        }

        $application->status = $to;
        $application->reject_reason = $reason;
        $application->audited_at = now();
        $application->audited_by = $reviewer->id;
        $application->save();

        return $application->refresh();
    }
}
