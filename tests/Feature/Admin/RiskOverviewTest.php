<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Actions\ApplyChargeback;
use App\Domains\Reporting\RiskOverview;
use App\Filament\Pages\RiskOverview as RiskPage;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Fraud dashboard: chargebacks and disputes per seller, open flags, frozen duplicate keys; staff access.
 */

it('ranks sellers by chargebacks and shows open flags and frozen keys', function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $fx = CheckoutFixture::build();
    $order = CheckoutFixture::deliveredOrder($fx);
    app(ApplyChargeback::class)->open(CheckoutFixture::pspPayment($order), 'case-risk-1', $order->total_amount);
    DB::table('risk_flags')->insert(['subject_type' => 'order', 'subject_id' => $order->id, 'rule' => 'velocity', 'score' => 70, 'status' => 'open', 'created_at' => now()]);

    $r = app(RiskOverview::class)->summary();

    expect($r['totals']['chargebacks'])->toBe(1)
        ->and($r['totals']['openFlags'])->toBeGreaterThanOrEqual(1)
        ->and(collect($r['flagsByRule'])->firstWhere('rule', 'velocity')['open'])->toBe(1)
        ->and($r['sellers'][0]['store'])->toBe($fx['seller']->store_name)
        ->and($r['sellers'][0]['chargebacks'])->toBe(1)
        ->and($r['sellers'][0]['chargebackBps'])->toBeGreaterThan(0);
});

it('is open to admins, moderators and finance only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    expect(RiskPage::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());
    expect(RiskPage::canAccess())->toBeTrue();
    Livewire::test(RiskPage::class)->assertOk()->assertSee('Sellers by chargebacks and disputes');
});
