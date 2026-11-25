<?php

declare(strict_types=1);

use App\Domains\Offers\Models\Offer;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;
use Tests\Support\OfferFixture;

/*
 * Game×category pages as SEO landing pages: keyword title with the lowest price, figures in the description,
 * AggregateOffer + FAQPage structured data mirrored by visible intro and FAQ.
 */

it('builds a buy-intent title, real figures and matching structured data', function (): void {
    $fx = OfferFixture::build();
    $gc = $fx['catalog']['gameCategory'];

    $this->get($gc->path('en'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('seo.title', fn ($t): bool => str_starts_with((string) $t, 'Buy '.$gc->title('en').' — from $'))
        ->where('seo.description', fn ($d): bool => str_contains((string) $d, 'verified sellers') && str_contains((string) $d, 'offers from $'))
        ->where('seo.jsonLd', fn ($ld): bool => collect($ld)->contains(fn (array $x): bool => $x['@type'] === 'FAQPage' && count($x['mainEntity']) === 4)
            && collect($ld)->contains(fn (array $x): bool => $x['@type'] === 'Product' && $x['offers']['@type'] === 'AggregateOffer' && $x['offers']['offerCount'] > 0))
        ->has('landing.faq', 4)
        ->where('landing.intro', fn ($i): bool => str_contains((string) $i, 'Compare')));
});

it('keeps a plain title and an alert hint when nothing is on sale', function (): void {
    $fx = CatalogFixture::build();
    $gc = $fx['gameCategory'];
    Offer::query()->where('game_category_id', $gc->id)->delete();

    $this->get($gc->path('en'))->assertInertia(fn (Assert $page) => $page
        ->where('seo.title', fn ($t): bool => str_starts_with((string) $t, 'Buy '.$gc->title('en')) && ! str_contains((string) $t, 'from $'))
        ->where('landing.intro', fn ($i): bool => str_contains((string) $i, 'price alert')));
});
