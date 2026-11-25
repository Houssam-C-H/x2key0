<?php

declare(strict_types=1);

use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerLevel;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OfferFixture;

/*
 * Offers by CSV: export everything, import updates (by id) and creates (without id) through SaveOffer.
 */

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();
    $this->actingAs($this->fx['seller']);
    $this->post('/seller/offers', OfferFixture::payload($this->fx))->assertSessionHasNoErrors();
    $this->offer = Offer::query()->where('seller_profile_id', $this->fx['profile']->id)->latest('id')->firstOrFail();
});

function csvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('offers.csv', $content);
}

it('exports every offer of the store with a header', function (): void {
    $csv = $this->get('/seller/offers/export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect(str_getcsv($lines[0]))->toContain('id', 'title', 'unit_price')
        ->and($lines)->toHaveCount(2)
        ->and($csv)->toContain($this->offer->public_id)->toContain('12.50');
});

it('updates rows with an id, creates rows without, and reports bad rows by line', function (): void {
    $gc = $this->fx['catalog']['gameCategory']->id;
    $p2 = $this->fx['catalog']['products']['p2']->id;
    $csv = "id,game_category_id,product_id,title,unit_price,manual_stock_qty,delivery_method\n"
        ."{$this->offer->public_id},,,,9.99,40,\n"
        .",{$gc},{$p2},New CSV offer,4.5,10,digital_key\n"
        ."NOTANID,,,,1.00,,\n"
        .",{$gc},{$p2},Bad price,abc,10,digital_key\n";

    $this->post('/seller/offers/import', ['file' => csvUpload($csv)])->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionHas('csvErrors', fn (array $errors): bool => array_column($errors, 'line') === [4, 5]);

    expect($this->offer->refresh()->unit_price_amount)->toBe(999)
        ->and($this->offer->manual_stock_qty)->toBe(40)
        ->and($this->offer->title)->toBe('Alpha Standard — instant Steam key')
        ->and(Offer::query()->where('seller_profile_id', $this->fx['profile']->id)->where('title', 'New CSV offer')->value('unit_price_amount'))->toBe(450);
});

it('refuses a file without the expected header', function (): void {
    $this->post('/seller/offers/import', ['file' => csvUpload("foo,bar\n1,2\n")])
        ->assertSessionHas('csvErrors', fn (array $errors): bool => $errors[0]['line'] === 1);
    $this->get('/seller/offers')->assertInertia(fn (Assert $page) => $page->component('Seller/Offers/Index'));
});
