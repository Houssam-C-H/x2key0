<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Sellers\Models\SellerProfile;
use App\Filament\Resources\Offers\Pages\ListOffers;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CatalogFixture;

function staffUser(StaffRole $role = StaffRole::Moderator): User
{
    return User::factory()->staff($role)->withTwoFactor()->create();
}

beforeEach(function (): void {
    $this->fx = CatalogFixture::build();
    $this->pending = Offer::factory()->forProduct($this->fx['products']['p3'])->status(OfferStatus::PendingReview)->create(['published_at' => null, 'expires_at' => null, 'unit_price_amount' => 700]);
});

it('opens the moderation queue and storage groups for moderators and merchant managers, not for support', function (): void {
    foreach ([StaffRole::Moderator, StaffRole::MerchantManager] as $role) {
        $this->actingAs(staffUser($role))->get('/admin/offers')->assertOk();
        $this->get('/admin/storage-groups')->assertOk();
    }

    $this->actingAs(staffUser(StaffRole::Support))->get('/admin/offers')->assertForbidden();
});

it('approves: the offer goes live and the product shows its price', function (): void {
    $this->actingAs($mod = staffUser());

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$this->pending])
        ->callAction(TestAction::make('approve')->table($this->pending));

    $offer = $this->pending->refresh();
    expect($offer->status)->toBe(OfferStatus::Active)
        ->and($offer->expires_at)->not->toBeNull()
        ->and($offer->statusChanges()->latest('id')->first()?->actor_id)->toBe($mod->id)
        ->and($this->fx['products']['p3']->refresh()->lowest_price_amount_cached)->toBe(700)
        ->and($this->fx['products']['p3']->offer_count_cached)->toBe(1);
});

it('rejects with a reason the seller sees, system-deactivates live offers and restores them', function (): void {
    $this->actingAs(staffUser());

    Livewire::test(ListOffers::class)->callAction(TestAction::make('reject')->table($this->pending), ['reason' => 'Misleading title']);
    expect($this->pending->refresh()->status)->toBe(OfferStatus::Rejected)
        ->and($this->pending->status_reason)->toBe('Misleading title');

    $live = Offer::factory()->forProduct($this->fx['products']['p3'])->create();
    Livewire::test(ListOffers::class)->filterTable('status', 'active')
        ->callAction(TestAction::make('systemDeactivate')->table($live), ['reason' => 'Counterfeit report']);
    expect($live->refresh()->status)->toBe(OfferStatus::SystemDeactivated)
        ->and($live->status_reason)->toBe('Counterfeit report');

    // The seller can neither relist it; staff restore it to deactivated.
    expect(fn () => app(OfferStateMachine::class)->publish($live, null))->toThrow(OfferException::class);
    Livewire::test(ListOffers::class)->filterTable('status', 'system_deactivated')->callAction(TestAction::make('restore')->table($live));
    expect($live->refresh()->status)->toBe(OfferStatus::Deactivated);
});

it('never lets staff moderate offers of their own store — admins included', function (): void {
    $admin = staffUser(StaffRole::Admin);
    $own = Offer::factory()->status(OfferStatus::PendingReview)->create([
        'seller_profile_id' => SellerProfile::factory()->create(['user_id' => $admin->id])->id,
        'published_at' => null,
    ]);

    $this->actingAs($admin);
    Livewire::test(ListOffers::class)
        ->assertActionHidden(TestAction::make('approve')->table($own))
        ->assertActionVisible(TestAction::make('approve')->table($this->pending));
});
