<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Audit\AuditLog;
use App\Shared\Support\Settings;

it('falls back to the Part 27 defaults', function (): void {
    $settings = app(Settings::class);

    expect($settings->int('commission_bps'))->toBe(800)
        ->and($settings->int('buyer_fee_min_amount'))->toBe(30)
        ->and($settings->int('settlement_hold_days.accounts'))->toBe(14)
        ->and($settings->bool('credits_topup_enabled'))->toBeFalse();
});

it('lets staff override a key, audits it, and reset restores the default', function (): void {
    $staff = User::factory()->create();
    $settings = app(Settings::class);

    $settings->set('commission_bps', 900, $staff);

    expect($settings->int('commission_bps'))->toBe(900);

    $log = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($log->actor_id)->toBe($staff->id)
        ->and($log->before)->toBe(['key' => 'commission_bps', 'value' => 800])
        ->and($log->after)->toBe(['key' => 'commission_bps', 'value' => 900]);

    $settings->reset('commission_bps', $staff);

    expect($settings->int('commission_bps'))->toBe(800)
        ->and(AuditLog::query()->where('action', 'settings.reset')->count())->toBe(1);
});

it('reads nested values from an overridden map', function (): void {
    $settings = app(Settings::class);
    $holds = $settings->get('settlement_hold_days');
    $holds['accounts'] = 21;

    $settings->set('settlement_hold_days', $holds);

    expect($settings->int('settlement_hold_days.accounts'))->toBe(21)
        ->and($settings->int('settlement_hold_days.top_up'))->toBe(3);
});

it('survives a fresh instance (persisted + cache flushed)', function (): void {
    app(Settings::class)->set('listing_duration_days', 10);
    app()->forgetScopedInstances();

    expect(app(Settings::class)->int('listing_duration_days'))->toBe(10);
});

it('rejects unknown keys', function (): void {
    app(Settings::class)->get('comission_bps');
})->throws(InvalidArgumentException::class, 'Unknown setting [comission_bps]');

it('rejects type mismatches on typed getters', function (): void {
    app(Settings::class)->int('refund_default_destination');
})->throws(InvalidArgumentException::class);
