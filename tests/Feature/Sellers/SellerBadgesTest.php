<?php

declare(strict_types=1);

use App\Domains\Certification\Actions\ReviewCertification;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Sellers\Support\SellerBadges;

/*
 * Public trust badges from facts X2KEY checked: ID verified, top rated, fast delivery, years on X2KEY.
 */

function badgeStats(SellerProfile $p, array $values): void
{
    SellerStats::query()->updateOrCreate(['seller_profile_id' => $p->id], [
        'orders_completed' => 0, 'orders_cancelled_by_seller' => 0, 'disputes_opened' => 0, 'disputes_lost' => 0,
        'completion_rate_bps' => null, 'positive_rate_bps' => null, 'positive_rate_90d_bps' => null, 'rating_count' => 0,
        'avg_delivery_minutes' => null, 'sales_30d_amount' => 0, 'violation_points' => 0, ...$values,
    ]);
}

it('gives no badge to a new store', function (): void {
    $p = SellerProfile::factory()->create(['activated_at' => now()]);

    expect(SellerBadges::for($p))->toBe(['badges' => [], 'years' => 0, 'sales' => 0]);
});

it('earns top rated, fast delivery and years only past the thresholds', function (): void {
    $p = SellerProfile::factory()->create(['activated_at' => now()->subYears(2)->subDay()]);
    badgeStats($p, ['positive_rate_bps' => 9900, 'rating_count' => 25, 'avg_delivery_minutes' => 12, 'orders_completed' => 40]);

    expect(SellerBadges::for($p))->toBe(['badges' => ['top_rated', 'fast', 'years'], 'years' => 2, 'sales' => 40]);

    $q = SellerProfile::factory()->create(['activated_at' => now()->subMonths(6)]);
    badgeStats($q, ['positive_rate_bps' => 9900, 'rating_count' => 5, 'avg_delivery_minutes' => 45, 'orders_completed' => 3]);
    expect(SellerBadges::for($q)['badges'])->toBe([]);
});

it('shows ID verified as soon as the identity check is approved', function (): void {
    $p = SellerProfile::factory()->create();
    expect(SellerBadges::for($p)['badges'])->not->toContain('verified');

    $cert = Certification::query()->create(['user_id' => $p->user_id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Pending]);
    app(ReviewCertification::class)->approve($cert, null);

    expect(SellerBadges::for($p)['badges'])->toContain('verified');
});
