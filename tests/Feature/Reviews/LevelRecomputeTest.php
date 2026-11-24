<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserLevel;
use App\Domains\Sellers\Actions\RecomputeLevels;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use Database\Seeders\SellerLevelSeeder;
use Database\Seeders\UserLevelSeeder;

beforeEach(function (): void {
    (new SellerLevelSeeder)->run();
    (new UserLevelSeeder)->run();
});

function statsFor(array $values): SellerStats
{
    $stats = new SellerStats;
    $stats->forceFill([
        'orders_completed' => 0, 'orders_cancelled_by_seller' => 0, 'disputes_opened' => 0, 'disputes_lost' => 0,
        'completion_rate_bps' => null, 'positive_rate_bps' => null, 'rating_count' => 0, 'violation_points' => 0,
        ...$values,
    ]);

    return $stats;
}

it('picks the highest level whose thresholds all hold', function (array $values, string $expected): void {
    expect(RecomputeLevels::levelFor(statsFor($values))?->code)->toBe($expected);
})->with([
    'brand new' => [[], 'new'],
    'bronze: 20 orders, 90 % completion' => [['orders_completed' => 25, 'completion_rate_bps' => 9100], 'bronze'],
    'silver needs a good rating' => [['orders_completed' => 150, 'completion_rate_bps' => 9500, 'positive_rate_bps' => 8000], 'bronze'],
    'silver' => [['orders_completed' => 150, 'completion_rate_bps' => 9500, 'positive_rate_bps' => 9600], 'silver'],
    'gold' => [['orders_completed' => 600, 'completion_rate_bps' => 9600, 'positive_rate_bps' => 9600], 'gold'],
    'too many lost disputes for gold' => [['orders_completed' => 600, 'completion_rate_bps' => 9600, 'positive_rate_bps' => 9600, 'disputes_lost' => 18], 'silver'],
    'too many violation points' => [['orders_completed' => 600, 'completion_rate_bps' => 9600, 'positive_rate_bps' => 9600, 'violation_points' => 11], 'bronze'],
]);

it('recomputes stats and moves a seller between levels with a notification, but keeps locked levels', function (): void {
    $profile = SellerProfile::factory()->create(['seller_level_id' => SellerLevel::query()->where('code', 'gold')->value('id')]);
    SellerStats::query()->updateOrCreate(['seller_profile_id' => $profile->id], ['orders_completed' => 999]); // stale counter

    expect(app(RecomputeLevels::class)->seller($profile))->toBeTrue()
        ->and($profile->refresh()->level->code)->toBe('new')
        ->and(SellerStats::query()->where('seller_profile_id', $profile->id)->value('orders_completed'))->toBe(0)
        ->and(InboxItem::query()->where('user_id', $profile->user_id)->value('notification_type'))->toBe('seller_level_down');

    $locked = SellerProfile::factory()->create(['seller_level_id' => SellerLevel::query()->where('code', 'gold')->value('id'), 'level_locked' => true]);
    expect(app(RecomputeLevels::class)->seller($locked))->toBeFalse()->and($locked->refresh()->level->code)->toBe('gold');

    $this->artisan('levels:recompute')->assertSuccessful();
});

it('sets the buyer level from lifetime completed spend', function (): void {
    $levels = UserLevel::query()->orderBy('min_spend_amount')->get();
    $user = User::factory()->create(['user_level_id' => $levels->last()->id]);

    expect(app(RecomputeLevels::class)->user($user))->toBeTrue()
        ->and($user->refresh()->user_level_id)->toBe($levels->first()->id);
});
