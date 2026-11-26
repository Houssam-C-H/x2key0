<?php

declare(strict_types=1);

use App\Domains\Affiliate\Actions\AffiliateAccounts;
use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Domains\Affiliate\Models\AffiliateReferral;
use App\Domains\Affiliate\Support\AffiliateBills as Bills;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Filament\Pages\AffiliateBills;
use App\Filament\Resources\Affiliates\Pages\ListAffiliateCommissions;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Affiliate commissions, monthly bills and transfers (docs/ADMIN_PLAN.md item 8).
 */

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
});

it('lists commissions by status and sums the month per affiliate from the ledger', function (): void {
    $fx = CheckoutFixture::build();
    $affiliateUser = User::factory()->create(['username' => 'aff_admin_view']);
    $affiliate = app(AffiliateAccounts::class)->enrol($affiliateUser);
    $r = new AffiliateReferral;
    $r->forceFill(['affiliate_id' => $affiliate->id, 'referred_user_id' => $fx['buyer']->id, 'signed_up_at' => now(), 'attribution_expires_at' => now()->addDays(90)])->save();
    $fx['buyer']->forceFill(['referred_by_affiliate_id' => $affiliate->id])->save();

    $order = CheckoutFixture::deliveredOrder($fx);
    app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $fx['buyer']);
    $commission = AffiliateCommission::query()->sole();

    $bill = Bills::month(now());
    expect($bill)->toHaveCount(1)
        ->and($bill[0]['username'])->toBe('aff_admin_view')
        ->and($bill[0]['commission'])->toBe($commission->commission_amount)
        ->and($bill[0]['net'])->toBe($commission->commission_amount)
        ->and(Bills::month(now()->subMonths(2)))->toBe([]);

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    Livewire::test(ListAffiliateCommissions::class)
        ->assertCanSeeTableRecords([$commission])
        ->set('activeTab', 'released')
        ->assertCanNotSeeTableRecords([$commission])
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$commission]);
    Livewire::test(AffiliateBills::class)->assertOk()->assertSee('aff_admin_view')->assertSee('Transfers to Credits');
});

it('opens to finance and merchant managers, not support', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create());
    expect(AffiliateBills::canAccess())->toBeTrue();
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    expect(AffiliateBills::canAccess())->toBeFalse();
});
