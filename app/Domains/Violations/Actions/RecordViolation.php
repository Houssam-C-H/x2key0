<?php

declare(strict_types=1);

namespace App\Domains\Violations\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Models\Violation;
use App\Domains\Violations\Models\ViolationRule;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Raises a violation (spec 17.2): automatic rules from platform events (once per rule × order × dispute), or
 * staff for the others. New violations are `open`: the seller may appeal within `violation_appeal_days`;
 * points count once upheld and expire after `violation_points_ttl_days`.
 */
final class RecordViolation
{
    public function __construct(private readonly Settings $settings) {}

    public function auto(string $code, int $sellerProfileId, ?int $orderId = null, ?int $offerId = null, ?int $disputeId = null, ?string $details = null): ?Violation
    {
        $rule = ViolationRule::query()->where('code', $code)->where('is_enabled', true)->first();

        if ($rule === null) {
            return null;
        }

        $existing = Violation::query()->where('violation_rule_id', $rule->id)->where('seller_profile_id', $sellerProfileId)
            ->where('order_id', $orderId)->where('dispute_id', $disputeId)->first();

        return $existing ?? $this->create($rule, $sellerProfileId, null, $orderId, $offerId, $disputeId, $details);
    }

    public function manual(ViolationRule $rule, SellerProfile $seller, User $staff, ?string $details, ?int $orderId = null, ?int $offerId = null): Violation
    {
        return $this->create($rule, $seller->id, $staff, $orderId, $offerId, null, $details);
    }

    private function create(ViolationRule $rule, int $sellerProfileId, ?User $staff, ?int $orderId, ?int $offerId, ?int $disputeId, ?string $details): Violation
    {
        $violation = new Violation;
        $violation->forceFill([
            'seller_profile_id' => $sellerProfileId,
            'violation_rule_id' => $rule->id,
            'order_id' => $orderId,
            'offer_id' => $offerId,
            'dispute_id' => $disputeId,
            'points' => $rule->points,
            'details' => $details === null ? null : mb_substr($details, 0, 500),
            'status' => ViolationStatus::Open,
            'appeal_due_at' => now()->addDays($this->settings->int('violation_appeal_days')),
            'expires_at' => now()->addDays($this->settings->int('violation_points_ttl_days')),
            'created_by' => $staff?->id,
        ])->save();

        $userId = SellerProfile::query()->whereKey($sellerProfileId)->value('user_id');
        if (is_int($userId)) {
            $code = $rule->code;
            DB::afterCommit(fn () => Notifier::notify($userId, 'violation_new', 'merchant', ['rule' => $code, 'points' => $rule->points, 'href' => '/seller/violations']));
        }

        return $violation;
    }
}
