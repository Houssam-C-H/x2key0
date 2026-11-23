<?php

declare(strict_types=1);

use App\Shared\Money\Amount;
use App\Shared\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('money_cast_probes', function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('unit_price_amount')->nullable();
        $table->char('currency', 3)->nullable();
    });
});

final class MoneyCastProbe extends Model
{
    public $timestamps = false;

    protected $table = 'money_cast_probes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_price' => MoneyCast::class.':unit_price_amount,currency'];
    }
}

it('stores minor units as an integer plus currency and reads back a Money', function (): void {
    $probe = MoneyCastProbe::query()->create(['unit_price' => Amount::usd(8499)]);

    $row = DB::table('money_cast_probes')->find($probe->id);
    expect((int) $row->unit_price_amount)->toBe(8499)
        ->and($row->currency)->toBe('USD');

    $fresh = MoneyCastProbe::query()->findOrFail($probe->id);
    expect($fresh->unit_price)->toBeInstanceOf(Money::class)
        ->and($fresh->unit_price->isEqualTo(Amount::usd(8499)))->toBeTrue();
});

it('rejects non-Money values so floats can never sneak in', function (): void {
    MoneyCastProbe::query()->create(['unit_price' => 84.99]);
})->throws(InvalidArgumentException::class);

it('returns null when the amount is null', function (): void {
    $probe = MoneyCastProbe::query()->create(['unit_price' => null]);

    expect($probe->fresh()?->unit_price)->toBeNull();
});
