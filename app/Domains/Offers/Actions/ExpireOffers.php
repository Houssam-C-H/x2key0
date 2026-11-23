<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;

/**
 * Listings past their expiration date become `expired` (spec 6.2). Scheduled; idempotent — an offer that was
 * already expired, extended or deactivated meanwhile is skipped.
 */
final class ExpireOffers
{
    public function __construct(private readonly OfferStateMachine $machine) {}

    public function handle(): int
    {
        $expired = 0;

        Offer::query()
            ->whereIn('status', [OfferStatus::Active->value, OfferStatus::OutOfStock->value])
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(200, function ($offers) use (&$expired): void {
                foreach ($offers as $row) {
                    $offer = Offer::query()->find($row->id);
                    if ($offer === null) {
                        continue;
                    }

                    try {
                        if ($this->machine->expire($offer) !== null) {
                            $expired++;
                        }
                    } catch (OfferException) {
                        // Changed concurrently; the next run re-checks.
                    }
                }
            });

        return $expired;
    }
}
