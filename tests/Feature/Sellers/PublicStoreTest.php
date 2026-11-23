<?php

declare(strict_types=1);

use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use Inertia\Testing\AssertableInertia as Assert;

it('renders a public store with SEO in every locale', function (string $locale): void {
    $profile = SellerProfile::factory()->create([
        'store_name' => 'Pixel Vault',
        'slug' => 'pixel-vault',
        'bio' => ['en' => 'Fast keys.', 'fr' => 'Clés rapides.'],
        'store_notice' => ['en' => 'Back at 9am.'],
    ]);
    $profile->media?->update(['languages' => [['code' => 'fr', 'level' => 'native']]]);

    $this->get("/{$locale}/store/pixel-vault")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Store')
            ->where('store.name', 'Pixel Vault')
            ->where('store.bio', $locale === 'fr' ? 'Clés rapides.' : 'Fast keys.')
            ->where('store.notice', 'Back at 9am.')
            ->where('store.languages.0.code', 'fr')
            ->where('store.level.stars', 0)
            ->where('store.stats.ordersCompleted', 0)
            ->where('store.stats.disputeRateBps', null)
            ->where('isOwner', false)
            ->where('seo.canonical', url("/{$locale}/store/pixel-vault"))
            ->where('seo.noindex', false)
            ->has('seo.alternates', 4));
})->with(['en', 'fr', 'ar']);

it('hides inactive, suspended and banned stores', function (SellerStatus $status): void {
    SellerProfile::factory()->status($status)->create(['slug' => 'hidden-store']);

    $this->get('/en/store/hidden-store')->assertNotFound();
})->with([SellerStatus::Inactive, SellerStatus::Suspended, SellerStatus::Banned]);

it('404s for unknown slugs and lists public stores in the sitemap', function (): void {
    SellerProfile::factory()->create(['slug' => 'listed-store']);
    SellerProfile::factory()->status(SellerStatus::Suspended)->create(['slug' => 'unlisted-store']);

    $this->get('/en/store/nope')->assertNotFound();

    $xml = $this->get('/sitemap-en.xml')->assertOk()->getContent();
    expect($xml)->toContain(url('/en/store/listed-store'))
        ->not->toContain('unlisted-store');
});
