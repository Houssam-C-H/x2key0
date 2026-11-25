<?php

declare(strict_types=1);

namespace App\Domains\Certification\Actions;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Support\SellerBadges;
use DomainException;

/**
 * Staff decision on a pending certification (also used by provider webhooks with $reviewer = null).
 * Audited through Certification's Auditable trait.
 */
final class ReviewCertification
{
    public function approve(Certification $certification, ?User $reviewer): void
    {
        $this->assertPending($certification);

        $certification->forceFill([
            'status' => CertificationStatus::Verified,
            'verified_at' => now(),
            'reviewed_by' => $reviewer?->id,
            'rejected_reason' => null,
        ])->save();

        // The store's public "ID verified" badge follows at once.
        $profileId = SellerProfile::query()->where('user_id', $certification->user_id)->value('id');
        if (is_int($profileId)) {
            SellerBadges::forget($profileId);
        }
    }

    public function reject(Certification $certification, ?User $reviewer, string $reason): void
    {
        $this->assertPending($certification);

        $certification->forceFill([
            'status' => CertificationStatus::Rejected,
            'reviewed_by' => $reviewer?->id,
            'rejected_reason' => mb_substr($reason, 0, 500),
        ])->save();
    }

    private function assertPending(Certification $certification): void
    {
        if ($certification->status !== CertificationStatus::Pending) {
            throw new DomainException("Certification {$certification->id} is not pending.");
        }
    }
}
