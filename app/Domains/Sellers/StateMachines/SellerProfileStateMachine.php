<?php

declare(strict_types=1);

namespace App\Domains\Sellers\StateMachines;

use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStatusChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of seller_profiles.status (spec 6.3):
 * inactive → active (email + phone + identity verified) · active ⇄ vacation ·
 * active|vacation → suspended → active · any (except banned) → banned.
 * Each transition writes a seller_status_changes row in the same transaction.
 */
final class SellerProfileStateMachine
{
    /** Certifications required to activate (6.3). */
    public const array ACTIVATION_REQUIRES = [CertificationType::Email, CertificationType::Phone, CertificationType::Identity];

    public function activate(SellerProfile $profile, ?User $actor = null): SellerProfile
    {
        $user = $profile->user;
        $user->loadMissing('certifications');

        foreach (self::ACTIVATION_REQUIRES as $type) {
            if (! $user->isCertified($type)) {
                throw SellerException::because('seller.error.certification_missing', ['type' => SellerException::trans("certification.type.{$type->value}")]);
            }
        }

        return $this->transition($profile, SellerStatus::Active, $actor, null, function (SellerProfile $p): void {
            $p->activated_at ??= now();
        });
    }

    public function startVacation(SellerProfile $profile, ?Carbon $until, ?User $actor = null): SellerProfile
    {
        if ($until !== null && $until->isPast()) {
            throw SellerException::because('seller.error.vacation_past');
        }

        return $this->transition($profile, SellerStatus::Vacation, $actor, null, function (SellerProfile $p) use ($until): void {
            $p->vacation_until = $until;
        });
    }

    public function endVacation(SellerProfile $profile, ?User $actor = null): SellerProfile
    {
        if ($profile->status !== SellerStatus::Vacation) {
            throw SellerException::because('seller.error.not_on_vacation');
        }

        return $this->transition($profile, SellerStatus::Active, $actor, null, function (SellerProfile $p): void {
            $p->vacation_until = null;
        });
    }

    /** Staff, or a violation penalty (null actor). */
    public function suspend(SellerProfile $profile, ?User $actor, string $reason): SellerProfile
    {
        return $this->transition($profile, SellerStatus::Suspended, $actor, $reason);
    }

    public function reinstate(SellerProfile $profile, User $actor): SellerProfile
    {
        if ($profile->status !== SellerStatus::Suspended) {
            throw SellerException::because('seller.error.transition', ['from' => $profile->status->value, 'to' => SellerStatus::Active->value]);
        }

        return $this->transition($profile, SellerStatus::Active, $actor, null);
    }

    public function ban(SellerProfile $profile, ?User $actor, string $reason): SellerProfile
    {
        return $this->transition($profile, SellerStatus::Banned, $actor, $reason);
    }

    /**
     * @param  (callable(SellerProfile): void)|null  $mutate
     */
    private function transition(SellerProfile $profile, SellerStatus $to, ?User $actor, ?string $reason, ?callable $mutate = null): SellerProfile
    {
        return DB::transaction(function () use ($profile, $to, $actor, $reason, $mutate): SellerProfile {
            /** @var SellerProfile $locked */
            $locked = SellerProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw SellerException::because('seller.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }

            $reason = $reason === null ? null : mb_substr(trim($reason), 0, 500);

            $locked->status = $to;
            $locked->status_reason = in_array($to, [SellerStatus::Suspended, SellerStatus::Banned], true) ? $reason : null;

            if ($to !== SellerStatus::Vacation) {
                $locked->vacation_until = null;
            }

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->save();

            SellerStatusChange::query()->create([
                'seller_profile_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor?->id,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
