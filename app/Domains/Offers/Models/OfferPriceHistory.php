<?php

declare(strict_types=1);

namespace App\Domains\Offers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only unit price history (spec 5.4): one row per price set, including the first one.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $unit_price_amount
 * @property string $currency
 * @property int|null $changed_by
 * @property Carbon $created_at
 */
final class OfferPriceHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'offer_price_history';

    protected $fillable = ['offer_id', 'unit_price_amount', 'currency', 'changed_by'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Price history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_price_amount' => 'integer'];
    }
}
