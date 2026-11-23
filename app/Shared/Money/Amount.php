<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Brick\Money\SplitMode;

/**
 * Integer-only money helpers. Every amount crossing a boundary is a Brick Money;
 * every amount stored is an int of minor units. No floats anywhere.
 */
final class Amount
{
    public const int BPS_DENOMINATOR = 10_000;

    public static function ofMinor(int $minor, string $currency): Money
    {
        return Money::ofMinor($minor, $currency);
    }

    public static function usd(int $cents): Money
    {
        return Money::ofMinor($cents, 'USD');
    }

    public static function zero(string $currency): Money
    {
        return Money::zero($currency);
    }

    public static function toMinor(Money $money): int
    {
        return $money->getMinorAmount()->toInt();
    }

    /**
     * Applies basis points (1 bps = 0.01 %) with half-up rounding to the minor unit.
     */
    public static function bps(Money $money, int $bps): Money
    {
        return $money
            ->multipliedBy($bps, RoundingMode::HalfUp)
            ->dividedBy(self::BPS_DENOMINATOR, RoundingMode::HalfUp);
    }

    /**
     * Fee = max(amount × bps, minimum), all in the same currency.
     */
    public static function bpsWithMinimum(Money $money, int $bps, Money $minimum): Money
    {
        $fee = self::bps($money, $bps);

        return $fee->isLessThan($minimum) ? $minimum : $fee;
    }

    /**
     * Splits an amount into $parts minor-unit-exact pieces; the remainder goes to the first pieces.
     * Used for insurance installments so the parts always sum to the whole.
     *
     * @param  positive-int  $parts
     * @return list<Money>
     */
    public static function split(Money $money, int $parts): array
    {
        return $money->split($parts, SplitMode::ToFirst);
    }
}
