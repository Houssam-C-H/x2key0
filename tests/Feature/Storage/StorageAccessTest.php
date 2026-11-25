<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Actions\SetStorageAccess;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\ListSellerProfiles;
use App\Shared\Audit\AuditLog;
use Filament\Actions\Testing\TestAction;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/*
 * Staff switch for a seller's Storage (ADR-034).
 */

beforeEach(function (): void {
    $this->profile = SellerProfile::factory()->create();
    $this->seller = $this->profile->user;
    $this->staff = User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create();
    unlockPrivacy($this->seller);

    $this->group = StorageGroup::query()->create(['seller_profile_id' => $this->profile->id, 'name' => 'Keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 1]);
    $this->actingAs($this->seller)->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => "AAAA-1111\nBBBB-2222\nCCCC-3333"])->assertSessionHasNoErrors();
    $this->offer = Offer::factory()->fromStorage($this->group)->create(['title' => 'Alpha Steam key']);
    app(SetStorageAccess::class)->refreshGroups($this->profile);
});

it('switches Storage off: no writes, offers out of stock, seller told; on again restores stock', function (): void {
    expect($this->offer->refresh()->status)->toBe(OfferStatus::Active)
        ->and($this->group->refresh()->available_count_cached)->toBe(3);

    app(SetStorageAccess::class)->handle($this->profile, false, $this->staff, 'Duplicate keys reported');

    expect($this->profile->refresh()->storage_enabled)->toBeFalse()
        ->and($this->group->refresh()->available_count_cached)->toBe(0)
        ->and($this->offer->refresh()->status)->toBe(OfferStatus::OutOfStock)
        ->and(Offer::query()->live()->whereKey($this->offer->id)->exists())->toBeFalse()
        ->and(InboxItem::query()->where('user_id', $this->seller->id)->where('notification_type', 'storage_disabled')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'seller.storage_disabled')->value('after'))->toBe(['reason' => 'Duplicate keys reported']);

    // Every Storage write is refused while off; the keys are untouched.
    $this->actingAs($this->seller)->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => 'DDDD-4444'])->assertSessionHasErrors();
    $this->post('/seller/storage/groups', ['name' => 'More', 'type' => 'key', 'threshold' => 1])->assertSessionHasErrors();
    expect($this->group->items()->count())->toBe(3);

    // The Storage pages carry the banner flag.
    $this->get('/seller/storage')->assertInertia(fn (Assert $page) => $page->where('storageDisabled', true));

    app(SetStorageAccess::class)->handle($this->profile, true, $this->staff, 'Checked, all fine');

    expect($this->group->refresh()->available_count_cached)->toBe(3)
        ->and($this->offer->refresh()->status)->toBe(OfferStatus::Active)
        ->and(InboxItem::query()->where('user_id', $this->seller->id)->where('notification_type', 'storage_enabled')->exists())->toBeTrue();
    $this->actingAs($this->seller)->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => 'DDDD-4444'])->assertSessionHasNoErrors();
});

it('does not raise low-stock alerts for a switched-off Storage', function (): void {
    app(SetStorageAccess::class)->handle($this->profile, false, $this->staff, 'Review');

    $this->actingAs($this->seller)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('stockAlert', null));
});

it('gives merchant managers Storage off / on actions in the sellers list', function (): void {
    $this->actingAs($this->staff);

    Livewire::test(ListSellerProfiles::class)
        ->assertActionVisible(TestAction::make('storage_off')->table($this->profile))
        ->assertActionHidden(TestAction::make('storage_on')->table($this->profile))
        ->callAction(TestAction::make('storage_off')->table($this->profile), ['reason' => 'Chargeback wave'])
        ->assertHasNoFormErrors();

    expect($this->profile->refresh()->storage_enabled)->toBeFalse();
});
