<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Models\SettingChange;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Models\LedgerAdjustment;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Points\Models\PointsAdjustment;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Models\Ticket;

/**
 * "My work" for one staff member (docs/ADMIN_PLAN.md item 15): tickets assigned to them, the stores they manage with
 * what waits there, and approvals that need a second person they can give (never their own requests).
 */
final class MyAssignments
{
    /**
     * @return array{tickets: list<array<string, mixed>>, stores: list<array<string, mixed>>, approvals: list<array<string, mixed>>}
     */
    public static function for(User $staff): array
    {
        $tickets = Ticket::query()->with('user')->where('assigned_to', $staff->id)->where('status', '!=', TicketStatus::Closed)
            ->orderByRaw('case when first_response_due_at is null then 1 else 0 end')->orderBy('first_response_due_at')->limit(50)->get()
            ->map(fn (Ticket $t): array => [
                'id' => $t->public_id,
                'subject' => $t->subject,
                'user' => $t->user->username,
                'status' => Format::enum($t->status),
                'priority' => Format::enum($t->priority),
                'due' => Format::date($t->first_response_due_at),
                'overdue' => $t->first_responded_at === null && $t->first_response_due_at !== null && $t->first_response_due_at->isPast(),
            ])->all();

        $stores = SellerProfile::query()->where('merchant_manager_id', $staff->id)->orderBy('store_name')->limit(100)->get()
            ->map(fn (SellerProfile $p): array => [
                'id' => $p->id,
                'store' => $p->store_name,
                'status' => Format::enum($p->status),
                'offersToReview' => Offer::query()->where('seller_profile_id', $p->id)->where('status', OfferStatus::PendingReview)->count(),
                'applications' => SellerServiceApplication::query()->where('seller_profile_id', $p->id)->where('status', ServiceApplicationStatus::Pending)->count(),
            ])->all();

        $approvals = [];
        if ($staff->hasStaffRole(StaffRole::Finance) || $staff->hasStaffRole(StaffRole::Admin)) {
            foreach (LedgerAdjustment::query()->where('status', LedgerAdjustment::PENDING)->where('requested_by', '!=', $staff->id)->limit(50)->get() as $a) {
                $approvals[] = ['kind' => 'Ledger adjustment', 'what' => Format::money($a->amount, $a->currency).' · '.$a->account_code, 'reason' => $a->reason, 'at' => Format::date($a->created_at), 'url' => '/admin/ledger-adjustments'];
            }
            foreach (PointsAdjustment::query()->where('status', PointsAdjustment::PENDING)->where('requested_by', '!=', $staff->id)->limit(50)->get() as $p) {
                $approvals[] = ['kind' => 'Points adjustment', 'what' => number_format($p->points).' points', 'reason' => $p->reason, 'at' => Format::date($p->created_at), 'url' => '/admin/points/adjustments'];
            }
        }
        if ($staff->hasStaffRole(StaffRole::Admin)) {
            foreach (SettingChange::query()->where('status', SettingChange::PENDING)->where('requested_by', '!=', $staff->id)->limit(50)->get() as $c) {
                $approvals[] = ['kind' => 'Platform setting', 'what' => PolicyCatalog::label($c->key).': '.PolicyCatalog::display($c->key, $c->new_value), 'reason' => $c->reason, 'at' => Format::date($c->created_at), 'url' => '/admin/platform-settings'];
            }
        }

        return ['tickets' => array_values($tickets), 'stores' => array_values($stores), 'approvals' => $approvals];
    }
}
