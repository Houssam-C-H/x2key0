<?php

declare(strict_types=1);

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OfferFixture;

/*
 * Seller API v1 (ADR-038): tokens with abilities, offers / orders / Storage through the website's actions.
 */

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();
    $this->seller = $this->fx['seller'];
});

it('creates tokens after password confirmation and keeps Storage access behind two-step verification', function (): void {
    $this->actingAs($this->seller);
    $this->post('/seller/api/tokens', ['name' => 'Sync', 'abilities' => ['offers:read']])->assertRedirect(); // asks for the password
    expect(PersonalAccessToken::query()->count())->toBe(0);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/seller/api/tokens', ['name' => 'Keys', 'abilities' => ['storage:write']])->assertSessionHasErrors('abilities');

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/seller/api/tokens', ['name' => 'Sync', 'abilities' => ['offers:read', 'offers:write', 'bogus']])
        ->assertSessionHasErrors('abilities.2');

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/seller/api/tokens', ['name' => 'Sync', 'abilities' => ['offers:read', 'offers:write']])
        ->assertSessionHasNoErrors()->assertSessionHas('newToken');

    $token = PersonalAccessToken::query()->firstOrFail();
    expect($token->abilities)->toBe(['offers:read', 'offers:write']);

    $plain = session('newToken');
    auth()->logout();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/seller/offers')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/seller/orders')->assertForbidden();
});

it('refuses calls without a token or with a missing ability', function (): void {
    $this->getJson('/api/v1/seller/offers')->assertUnauthorized();

    Sanctum::actingAs($this->seller, ['orders:read']);
    $this->getJson('/api/v1/seller/offers')->assertForbidden();
});

it('creates, updates, deactivates and relists offers through the website rules', function (): void {
    Sanctum::actingAs($this->seller, ['offers:read', 'offers:write']);
    $gc = $this->fx['catalog']['gameCategory']->id;
    $p1 = $this->fx['catalog']['products']['p1']->id;

    $id = $this->postJson('/api/v1/seller/offers', ['game_category_id' => $gc, 'product_id' => $p1, 'title' => 'API key', 'unit_price' => '7.25', 'manual_stock_qty' => 5, 'delivery_method' => 'digital_key'])
        ->assertCreated()->assertJsonPath('data.unit_price', '7.25')->json('data.id');

    $this->postJson('/api/v1/seller/offers', ['game_category_id' => $gc, 'product_id' => $p1, 'title' => 'Bad', 'unit_price' => 'abc', 'manual_stock_qty' => 5, 'delivery_method' => 'digital_key'])
        ->assertUnprocessable()->assertJsonStructure(['message', 'field']);

    $this->patchJson("/api/v1/seller/offers/{$id}", ['unit_price' => '6.50', 'manual_stock_qty' => 9])->assertOk()
        ->assertJsonPath('data.unit_price', '6.50')->assertJsonPath('data.manual_stock_qty', 9)->assertJsonPath('data.title', 'API key');

    $this->postJson("/api/v1/seller/offers/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', OfferStatus::Deactivated->value);
    $this->postJson("/api/v1/seller/offers/{$id}/relist")->assertOk()->assertJsonPath('data.status', OfferStatus::Active->value);

    $this->getJson('/api/v1/seller/offers')->assertOk()->assertJsonPath('meta.total', 1);

    $foreign = Offer::factory()->create();
    $this->getJson("/api/v1/seller/offers/{$foreign->public_id}")->assertNotFound();
});

it('adds Storage items without echoing them back', function (): void {
    $group = StorageGroup::query()->create(['seller_profile_id' => $this->fx['profile']->id, 'name' => 'Keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 1]);
    Sanctum::actingAs($this->seller, ['storage:write']);

    $response = $this->postJson("/api/v1/seller/storage/groups/{$group->id}/items", ['items' => "AAAA-1111\nBBBB-2222\nAAAA-1111"])->assertCreated();

    expect($response->json('data.accepted'))->toBe(2)
        ->and($response->json('data.available'))->toBe(2)
        ->and($response->getContent())->not->toContain('AAAA-1111');
});
