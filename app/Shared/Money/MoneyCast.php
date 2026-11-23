<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps an `{x}_amount` bigint column + a currency column to a Brick Money.
 *
 * Usage in a model:
 *   'unit_price' => MoneyCast::class.':unit_price_amount,currency'
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn,
        private readonly string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($amount === null || $currency === null) {
            return null;
        }

        if (! is_string($currency)) {
            throw new InvalidArgumentException("Column {$this->currencyColumn} must hold an ISO 4217 code.");
        }

        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^-?\d+$/', $amount) === 1)) {
            throw new InvalidArgumentException("Column {$this->amountColumn} must hold integer minor units.");
        }

        return Money::ofMinor((int) $amount, $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("{$key} must be set with a Brick\\Money\\Money instance.");
        }

        return [
            $this->amountColumn => Amount::toMinor($value),
            $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
        ];
    }
}
