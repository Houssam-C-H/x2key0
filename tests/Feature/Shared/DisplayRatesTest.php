<?php

declare(strict_types=1);

use App\Shared\Money\DisplayRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Display currencies (ADR-036): daily rates, approximate prices for the chosen currency, USD charges.
 */

it('stores display rates from the rate service and shares the chosen one', function (): void {
    Http::fake([DisplayRates::URL => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'EUR' => 0.92, 'MAD' => 9.95, 'XYZ' => 3]])]);

    $this->artisan('fx:refresh')->assertSuccessful();

    expect(DB::table('fx_rates')->pluck('per_usd', 'currency')->map(fn ($v) => (float) $v)->all())->toEqual(['EUR' => 0.92, 'MAD' => 9.95])
        ->and(app(DisplayRates::class)->for('MAD'))->toBe(9.95)
        ->and(app(DisplayRates::class)->for('USD'))->toBeNull();

    $this->withUnencryptedCookie('currency', 'MAD')->get('/en')->assertInertia(fn (Assert $page) => $page->where('fx.currency', 'MAD')->where('fx.rate', 9.95));
});

it('ignores stale rates and fails loudly when the service is down', function (): void {
    DB::table('fx_rates')->insert(['currency' => 'EUR', 'per_usd' => '0.9', 'source' => 'test', 'fetched_at' => now()->subDays(5)]);
    expect(app(DisplayRates::class)->for('EUR'))->toBeNull();

    Http::fake([DisplayRates::URL => Http::response([], 503)]);
    $this->artisan('fx:refresh')->assertFailed();
});
