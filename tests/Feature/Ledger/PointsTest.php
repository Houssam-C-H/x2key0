<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserLevel;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Orders\Models\Order;
use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Points\Actions\PostPointsJournal;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Points\Models\PointsRule;
use App\Domains\Points\Support\Points;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->actingAs($this->fx['buyer']);
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['quantity' => 3])); // total $30.90
    $this->order = CheckoutFixture::lastOrder($this->fx['buyer']);
});

it('earns floor(USD × 10 × level multiplier) once per order (spec 7.7)', function (): void {
    $award = app(AwardOrderPoints::class);

    expect($award->pointsFor($this->order))->toBe(309); // 30.90 × 10, no level = ×1

    $this->fx['buyer']->forceFill(['user_level_id' => UserLevel::query()->where('code', 'platinum')->value('id')])->save();
    $order = Order::query()->findOrFail($this->order->id);
    expect($award->pointsFor($order))->toBe(417); // 309 × 1.35 = 417.15 → 417

    $award->earn($order);
    $award->earn($order);
    expect(Points::balance($this->fx['buyer']->id))->toBe(417);
});

it('reverses exactly what was earned, and a disabled rule earns nothing', function (): void {
    $award = app(AwardOrderPoints::class);
    $award->earn($this->order);
    $award->reverse($this->order);
    $award->reverse($this->order);

    expect(Points::balance($this->fx['buyer']->id))->toBe(0);

    PointsRule::query()->update(['is_enabled' => false]);
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    expect($award->earn(CheckoutFixture::lastOrder($this->fx['buyer'])))->toBeNull();
});

it('refuses unbalanced points journals and spending points the user does not have', function (): void {
    $post = app(PostPointsJournal::class);
    $user = User::factory()->create();

    expect(fn () => $post->handle(PointsJournalType::Adjust, 'bad', [[PointsAccountCode::User, $user->id, 10], [PointsAccountCode::Issued, 0, -9]]))
        ->toThrow(LedgerException::class)
        ->and(fn () => $post->handle(PointsJournalType::RedeemCoupon, 'spend', [[PointsAccountCode::User, $user->id, -10], [PointsAccountCode::Redeemed, 0, 10]], noOverdrawUsers: [$user->id]))
        ->toThrow(BalanceOverdrawn::class)
        ->and(Points::balance($user->id))->toBe(0);
});
