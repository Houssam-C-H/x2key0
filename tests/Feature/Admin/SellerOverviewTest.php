<?php

declare(strict_types=1);

use App\Domains\Engagement\Models\PriceAlert;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\ViewSellerProfile;
use App\Shared\Audit\AuditLog;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Store 360° page (docs/ADMIN_PLAN.md item 10).
 */

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->seller = $this->fx['seller'];
});

it('shows sales, switches, tokens and price alerts, and revokes a token with a reason', function (): void {
    CheckoutFixture::deliveredOrder($this->fx);
    $plain = $this->seller->user->createToken('erp-sync', ['offers:read'])->plainTextToken;
    $token = $this->seller->user->tokens()->sole();
    $productId = $this->fx['manual']->product_id;
    if ($productId !== null) {
        $alert = new PriceAlert;
        $alert->forceFill(['user_id' => User::factory()->create()->id, 'product_id' => $productId, 'target_amount' => 1, 'currency' => 'USD'])->save();
    }

    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create());
    $page = Livewire::test(ViewSellerProfile::class, ['record' => $this->seller->getRouteKey()])
        ->assertOk()
        ->assertSee($this->seller->store_name)
        ->assertSee('Orders paid, last 30 days')
        ->assertSee('erp-sync')
        ->assertDontSee($plain);
    if ($productId !== null) {
        $page->assertSee('Below store price');
    }

    $page->call('revokeToken', $token->id)->assertNotified(); // no reason → refused
    expect($this->seller->user->tokens()->count())->toBe(1);

    $page->set('revokeReason', 'Key leaked in a public repo')->call('revokeToken', $token->id);
    expect($this->seller->user->tokens()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'api_token.revoked_by_staff')->value('after'))->toMatchArray(['reason' => 'Key leaked in a public repo']);
});

it('suspends the store and switches Storage off from the page, and takes notes', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create());

    Livewire::test(ViewSellerProfile::class, ['record' => $this->seller->getRouteKey()])
        ->callAction(TestAction::make('storage_off'), ['reason' => 'Duplicate keys reported'])
        ->callAction(TestAction::make('suspend'), ['reason' => 'Investigation'])
        ->callAction(TestAction::make('note'), ['body' => 'Spoke with the owner'])
        ->assertSee('Spoke with the owner');

    expect($this->seller->refresh()->storage_enabled)->toBeFalse()
        ->and($this->seller->status)->toBe(SellerStatus::Suspended);
});
