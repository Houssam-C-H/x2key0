<?php

declare(strict_types=1);

namespace App\Domains\Violations\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use App\Domains\Violations\Enums\Penalty;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Exceptions\ViolationException;
use App\Domains\Violations\Models\Violation;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Appeal and decision of a violation (spec 6.7 / 17.2), and the penalties of upheld violations:
 *
 * - the rule's own penalty (listing suspension of the offer, store suspension, ban);
 * - points thresholds over the store's active points: ≥ `violation_points_store_suspend` → store suspended,
 *   ≥ `violation_points_ban` → banned (warning below that).
 *
 * `seller_stats.violation_points` mirrors the active total. Every step is audited (Auditable).
 */
final class DecideViolation
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SellerProfileStateMachine $sellers,
        private readonly OfferStateMachine $offers,
    ) {}

    public function appeal(Violation $violation, User $seller, string $text): Violation
    {
        return DB::transaction(function () use ($violation, $seller, $text): Violation {
            /** @var Violation $locked */
            $locked = Violation::query()->with('seller')->lockForUpdate()->findOrFail($violation->id);

            if ($locked->seller->user_id !== $seller->id) {
                throw ViolationException::because('violation.error.not_yours');
            }
            if (! $locked->canAppeal()) {
                throw ViolationException::because('violation.error.appeal_closed');
            }

            $locked->forceFill(['status' => ViolationStatus::Appealed, 'appeal_text' => mb_substr(trim($text), 0, 2000), 'appealed_at' => now()])->save();

            return $locked->refresh();
        });
    }

    public function uphold(Violation $violation, ?User $staff, ?string $note = null): Violation
    {
        return $this->decide($violation, ViolationStatus::Upheld, $staff, $note);
    }

    public function revoke(Violation $violation, User $staff, ?string $note = null): Violation
    {
        return $this->decide($violation, ViolationStatus::Revoked, $staff, $note);
    }

    /** Recomputes the store's active points and applies threshold penalties. */
    public function evaluate(SellerProfile $seller, ?Violation $trigger = null): void
    {
        $points = (int) Violation::query()->active()->where('seller_profile_id', $seller->id)->sum('points');
        SellerStats::query()->where('seller_profile_id', $seller->id)->update(['violation_points' => $points]);

        $penalty = $trigger?->rule->penalty;
        $seller->refresh();

        if ($penalty === Penalty::ListingSuspend && $trigger->offer_id !== null) {
            $offer = Offer::query()->find($trigger->offer_id);
            if ($offer !== null) {
                try {
                    $this->offers->systemDeactivate($offer, null, "violation:{$trigger->rule->code}");
                } catch (OfferException) {
                    // already deactivated or deleted
                }
            }
        }

        $reason = $trigger === null ? 'violation_points' : "violation:{$trigger->rule->code}";

        if (($penalty === Penalty::Ban || $points >= $this->settings->int('violation_points_ban')) && $seller->status->canTransitionTo(SellerStatus::Banned)) {
            $this->sellers->ban($seller, null, "{$reason} ({$points} points)");

            return;
        }

        if (($penalty === Penalty::StoreSuspend || $points >= $this->settings->int('violation_points_store_suspend'))
            && $seller->status->canTransitionTo(SellerStatus::Suspended)) {
            $this->sellers->suspend($seller, null, "{$reason} ({$points} points)");
        }
    }

    private function decide(Violation $violation, ViolationStatus $to, ?User $staff, ?string $note): Violation
    {
        $decided = DB::transaction(function () use ($violation, $to, $staff, $note): Violation {
            /** @var Violation $locked */
            $locked = Violation::query()->with(['seller', 'rule'])->lockForUpdate()->findOrFail($violation->id);

            if (! $locked->status->canTransitionTo($to)) {
                throw ViolationException::because('violation.error.decided');
            }
            if ($staff !== null && $locked->seller->user_id === $staff->id) {
                throw ViolationException::because('violation.error.self');
            }

            $locked->forceFill([
                'status' => $to,
                'appeal_decided_by' => $staff?->id,
                'decision_note' => $note === null ? null : mb_substr(trim($note), 0, 500),
                'decided_at' => now(),
            ])->save();

            $this->evaluate($locked->seller, $to === ViolationStatus::Upheld ? $locked : null);

            return $locked->refresh();
        });

        $decided->loadMissing(['seller', 'rule']);
        $userId = $decided->seller->user_id;
        $data = ['rule' => $decided->rule->code, 'status' => $to->value, 'href' => '/seller/violations'];
        DB::afterCommit(fn () => Notifier::notify($userId, 'violation_decided', 'merchant', $data));

        return $decided;
    }
}
