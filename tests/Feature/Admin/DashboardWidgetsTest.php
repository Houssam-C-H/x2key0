<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Reporting\DailySeries;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\TodayStats;
use App\Filament\Widgets\WorkQueues;
use Livewire\Livewire;

/*
 * Admin home: today's figures and the sales chart for admins/finance, work queues and system health for all staff.
 */

it('builds zero-filled daily series ending today', function (): void {
    User::factory()->count(2)->create();

    $s = DailySeries::last(14);

    expect($s['days'])->toHaveCount(14)
        ->and(end($s['days']))->toBe(now()->toDateString())
        ->and(end($s['users']))->toBe(2)
        ->and(array_sum($s['orders']))->toBe(0);
});

it('shows money widgets to admins and finance only, queues and health to every staff member', function (): void {
    $support = User::factory()->staff(StaffRole::Support)->withTwoFactor()->create();
    $this->actingAs($support);
    expect(TodayStats::canView())->toBeFalse()->and(SalesChart::canView())->toBeFalse()->and(WorkQueues::canView())->toBeTrue();

    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $this->actingAs($finance);
    expect(TodayStats::canView())->toBeTrue()->and(SalesChart::canView())->toBeTrue();

    $this->get('/admin')->assertOk();
});

it('counts waiting work and renders every widget', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    Offer::factory()->status(OfferStatus::PendingReview)->create();

    Livewire::test(WorkQueues::class)->assertOk()->assertSee('Offers to review')->assertSee('Open the queue');
    Livewire::test(TodayStats::class)->assertOk()->assertSee('Sales (GMV)')->assertSee('vs yesterday');
    Livewire::test(SalesChart::class)->assertOk();
    Livewire::test(SystemHealth::class)->assertOk()->assertSee('database');
});
