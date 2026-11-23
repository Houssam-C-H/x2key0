<?php

declare(strict_types=1);

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\LowStockAlert;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->profile = SellerProfile::factory()->create();
    $this->seller = $this->profile->user;
    $this->actingAs($this->seller);
    unlockPrivacy($this->seller);

    $this->group = StorageGroup::query()->create(['seller_profile_id' => $this->profile->id, 'name' => 'Keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 3]);
    $this->offer = Offer::factory()->fromStorage($this->group)->create(['title' => 'Alpha Steam key']);
});

it('turns an empty auto-delivery offer out of stock and shows the alert bar on dashboard pages', function (): void {
    Artisan::call('storage:check-low-stock');

    expect($this->offer->refresh()->status)->toBe(OfferStatus::OutOfStock)
        ->and(LowStockAlert::query()->open()->count())->toBe(1);

    $this->get('/seller/offers')->assertInertia(fn (Assert $page) => $page
        ->where('stockAlert.count', 1)
        ->where('stockAlert.titles', ['Alpha Steam key']));

    // Public pages and other users never get it.
    $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('stockAlert', null));
    $this->actingAs(SellerProfile::factory()->create()->user)->get('/seller/offers')->assertInertia(fn (Assert $page) => $page->where('stockAlert', null));
});

it('restocks: import above the threshold re-activates the offer and resolves the alert', function (): void {
    Artisan::call('storage:check-low-stock');

    $this->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => "K1\nK2"]);
    expect($this->offer->refresh()->status)->toBe(OfferStatus::Active)
        ->and(LowStockAlert::query()->open()->count())->toBe(1); // 2 < threshold 3: still low

    $this->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => "K3\nK4"]);
    expect(LowStockAlert::query()->open()->count())->toBe(0)
        ->and(LowStockAlert::query()->whereNotNull('resolved_at')->count())->toBe(1);

    $this->get('/seller/offers')->assertInertia(fn (Assert $page) => $page->where('stockAlert', null));
});

it('dismisses the bar until the alert resolves and triggers again', function (): void {
    Artisan::call('storage:check-low-stock');

    $this->post('/seller/stock-alerts/dismiss')->assertRedirect();
    $this->get('/seller/offers')->assertInertia(fn (Assert $page) => $page->where('stockAlert', null));

    Artisan::call('storage:check-low-stock'); // still low: the dismissed alert stays open, no new alert
    expect(LowStockAlert::query()->count())->toBe(1);

    $this->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => "K1\nK2\nK3\nK4"]);
    StorageItem::query()->limit(3)->get()->each(fn (StorageItem $i) => $this->post('/seller/storage/items/manage', ['operation' => 'recycle', 'ids' => [$i->id]]));

    $this->get('/seller/offers')->assertInertia(fn (Assert $page) => $page->where('stockAlert.count', 1));
    expect(LowStockAlert::query()->count())->toBe(2);
});

it('is idempotent and ignores offers that are not listed', function (): void {
    $this->offer->forceFill(['status' => OfferStatus::Deactivated])->save();

    Artisan::call('storage:check-low-stock');
    Artisan::call('storage:check-low-stock');

    expect(LowStockAlert::query()->count())->toBe(0)
        ->and($this->offer->refresh()->status)->toBe(OfferStatus::Deactivated)
        ->and($this->offer->statusChanges()->count())->toBe(0);
});
