<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Listeners;

use App\Domains\Affiliate\Actions\AffiliateCommissions;
use App\Domains\Affiliate\Actions\TrackReferrals;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Events\OrderCompleted;
use App\Domains\Orders\Models\Order;
use Illuminate\Auth\Events\Registered;

/** Wires the Affiliate Program to sign-up (attribution) and order completion (AFFILIATE_ACCRUAL). */
final class AffiliateListeners
{
    public function __construct(
        private readonly AffiliateCommissions $commissions,
        private readonly TrackReferrals $referrals,
    ) {}

    public function completed(OrderCompleted $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order !== null) {
            $this->commissions->accrue($order);
        }
    }

    public function registered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->referrals->signup($event->user, request());
        }
    }
}
