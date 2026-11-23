<?php

declare(strict_types=1);

use App\Shared\Money\Amount;
use Brick\Money\Exception\MoneyMismatchException;

it('applies basis points with half-up rounding', function (int $cents, int $bps, int $expected): void {
    expect(Amount::toMinor(Amount::bps(Amount::usd($cents), $bps)))->toBe($expected);
})->with([
    'commission 8% of $95.00' => [9500, 800, 760],
    'fee 3% of $92.00' => [9200, 300, 276],
    'half rounds up (0.5 cent)' => [50, 100, 1],   // 0.50 → 1
    'below half rounds down' => [49, 100, 0],      // 0.49 → 0
    'zero bps' => [12345, 0, 0],
    'full 100%' => [12345, 10000, 12345],
]);

it('enforces a minimum fee', function (): void {
    $min = Amount::usd(30);

    expect(Amount::toMinor(Amount::bpsWithMinimum(Amount::usd(500), 300, $min)))->toBe(30)   // 3% of $5 = 15c → 30c
        ->and(Amount::toMinor(Amount::bpsWithMinimum(Amount::usd(9200), 300, $min)))->toBe(276);
});

it('splits into parts that always sum to the whole', function (int $cents, int $parts): void {
    $pieces = Amount::split(Amount::usd($cents), $parts);

    expect($pieces)->toHaveCount($parts)
        ->and(array_sum(array_map(Amount::toMinor(...), $pieces)))->toBe($cents);

    $values = array_map(Amount::toMinor(...), $pieces);
    expect(max($values) - min($values))->toBeLessThanOrEqual(1);
})->with([[8740, 3], [100, 3], [1, 3], [999_999_99, 7]]);

it('never mixes currencies', function (): void {
    Amount::usd(100)->plus(Amount::ofMinor(100, 'EUR'));
})->throws(MoneyMismatchException::class);

it('keeps large amounts exact (no float drift)', function (): void {
    $big = Amount::usd(PHP_INT_MAX - 1);

    expect(Amount::toMinor($big))->toBe(PHP_INT_MAX - 1);
});
