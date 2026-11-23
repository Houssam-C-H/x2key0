<?php

declare(strict_types=1);

namespace App\Domains\Offers\StateMachines;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Actions\RefreshProductOffers;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Enums\PublishMode;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Models\OfferStatusChange;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of offers.status (spec 6.2). Every transition locks the row, checks the transition table
 * (OfferStatus::allowedNext), writes an offer_status_changes row in the same transaction and refreshes the
 * product's offer caches after commit.
 *
 * "Going live" resolves to pending_review when the seller level has no auto-approve (or the offer is a
 * flagged duplicate), otherwise to active — or out_of_stock when there is nothing to sell.
 */
final class OfferStateMachine
{
    public function __construct(private readonly RefreshProductOffers $refresh) {}

    /** First placement of a new offer (draft → pending_publish | review | live). */
    public function place(Offer $offer, ?User $actor): Offer
    {
        if ($offer->publish_mode === PublishMode::Pending) {
            return $this->transition($offer, OfferStatus::PendingPublish, $actor);
        }

        return $this->goLive($offer, $actor);
    }

    /** Seller publishes or relists: pending_publish, rejected, deactivated or expired → review | live. */
    public function publish(Offer $offer, ?User $actor): Offer
    {
        if (! $offer->status->sellerCanRelist()) {
            throw OfferException::because('offer.error.transition', ['from' => $offer->status->value, 'to' => OfferStatus::Active->value]);
        }

        return $this->goLive($offer, $actor);
    }

    /** Content edits by a seller without auto-approve send a listed offer back to review. */
    public function requireReview(Offer $offer, ?User $actor): Offer
    {
        if ($offer->status === OfferStatus::PendingReview || $offer->status === OfferStatus::PendingPublish || $offer->status === OfferStatus::Draft) {
            return $offer;
        }

        return $this->transition($offer, OfferStatus::PendingReview, $actor, 'edited');
    }

    public function approve(Offer $offer, User $moderator): Offer
    {
        if ($offer->status !== OfferStatus::PendingReview) {
            throw OfferException::because('offer.error.transition', ['from' => $offer->status->value, 'to' => OfferStatus::Active->value]);
        }

        return $this->transition($offer, $offer->stock() > 0 ? OfferStatus::Active : OfferStatus::OutOfStock, $moderator, null, self::listed(...));
    }

    public function reject(Offer $offer, User $moderator, string $reason): Offer
    {
        return $this->transition($offer, OfferStatus::Rejected, $moderator, $reason);
    }

    public function deactivate(Offer $offer, ?User $actor): Offer
    {
        if (! $offer->status->sellerCanDeactivate()) {
            throw OfferException::because('offer.error.transition', ['from' => $offer->status->value, 'to' => OfferStatus::Deactivated->value]);
        }

        return $this->transition($offer, OfferStatus::Deactivated, $actor);
    }

    /** Scheduled: listed offers past `expires_at`. Returns null when the offer no longer qualifies. */
    public function expire(Offer $offer): ?Offer
    {
        if (! $offer->status->tracksStock() || $offer->expires_at === null || $offer->expires_at->isFuture()) {
            return null;
        }

        return $this->transition($offer, OfferStatus::Expired, null, 'expired');
    }

    /** Follows stock: active at 0 → out_of_stock; out_of_stock with stock → active. */
    public function syncStock(Offer $offer): Offer
    {
        $stock = $offer->stock();

        return match (true) {
            $offer->status === OfferStatus::Active && $stock === 0 => $this->transition($offer, OfferStatus::OutOfStock, null, 'stock'),
            $offer->status === OfferStatus::OutOfStock && $stock > 0 => $this->transition($offer, OfferStatus::Active, null, 'stock'),
            default => $offer,
        };
    }

    /** Moderator or violation (spec 6.2 `* → system_deactivated`; null actor = automatic penalty), reason shown to the seller. */
    public function systemDeactivate(Offer $offer, ?User $staff, string $reason): Offer
    {
        return $this->transition($offer, OfferStatus::SystemDeactivated, $staff, $reason);
    }

    /** Staff lift a system deactivation; the seller relists it. */
    public function restore(Offer $offer, User $staff): Offer
    {
        if ($offer->status !== OfferStatus::SystemDeactivated) {
            throw OfferException::because('offer.error.transition', ['from' => $offer->status->value, 'to' => OfferStatus::Deactivated->value]);
        }

        return $this->transition($offer, OfferStatus::Deactivated, $staff);
    }

    private function goLive(Offer $offer, ?User $actor): Offer
    {
        $offer->loadMissing('seller.level');
        $level = $offer->seller->level;

        // Seller level offer limit (spec 3.3): listed and in-review offers count.
        if ($level !== null) {
            $listed = Offer::query()
                ->where('seller_profile_id', $offer->seller_profile_id)
                ->whereKeyNot($offer->id)
                ->whereIn('status', array_map(static fn (OfferStatus $s): string => $s->value, array_filter(OfferStatus::cases(), static fn (OfferStatus $s): bool => $s->countsTowardLimit())))
                ->count();

            if ($listed >= $level->max_active_offers) {
                throw OfferException::because('offer.error.limit', ['max' => $level->max_active_offers]);
            }
        }

        $review = $level?->auto_approve !== true || $offer->duplicate_of_offer_id !== null;

        if ($review) {
            return $this->transition($offer, OfferStatus::PendingReview, $actor);
        }

        return $this->transition($offer, $offer->stock() > 0 ? OfferStatus::Active : OfferStatus::OutOfStock, $actor, null, self::listed(...));
    }

    /** Listing period starts when the offer is listed (spec 1.8 #8). */
    private static function listed(Offer $offer): void
    {
        $offer->published_at ??= now();
        $offer->expires_at = now()->addDays($offer->listing_days);
    }

    /**
     * @param  (callable(Offer): void)|null  $mutate
     */
    private function transition(Offer $offer, OfferStatus $to, ?User $actor, ?string $reason = null, ?callable $mutate = null): Offer
    {
        $from = $offer->status;

        $result = DB::transaction(function () use ($offer, $to, $actor, $reason, $mutate, &$from): Offer {
            /** @var Offer $locked */
            $locked = Offer::query()->with(['seller.level', 'storageGroup'])->lockForUpdate()->findOrFail($offer->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw OfferException::because('offer.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }

            $reason = $reason === null ? null : mb_substr(trim($reason), 0, 500);

            $locked->status = $to;
            $locked->status_reason = in_array($to, [OfferStatus::Rejected, OfferStatus::SystemDeactivated], true) ? $reason : null;

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->save();

            OfferStatusChange::query()->create([
                'offer_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor?->id,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });

        if ($result->status->isLive() !== $from->isLive()) {
            $this->refresh->handle($result->product_id);
        }

        return $result;
    }
}
