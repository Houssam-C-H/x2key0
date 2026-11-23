<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment methods catalog (spec 5.7 / 9.1): drives the checkout list (and the footer strip in M6b).
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property string|null $logo_path
 * @property string $provider
 * @property list<string>|null $countries
 * @property list<string> $currencies
 * @property int $min_amount
 * @property int|null $max_amount
 * @property int $fee_bps
 * @property int $fee_fixed
 * @property bool $is_enabled
 * @property int $sort
 */
final class PaymentMethod extends Model
{
    use Auditable;
    use HasTranslations;

    protected $table = 'payment_methods_catalog';

    protected $fillable = ['code', 'name', 'logo_path', 'provider', 'countries', 'currencies', 'min_amount', 'max_amount', 'fee_bps', 'fee_fixed', 'is_enabled', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'countries' => 'array',
            'currencies' => 'array',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'fee_bps' => 'integer',
            'fee_fixed' => 'integer',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /** Usable for this buyer country, currency and amount (spec 9.1). */
    public function accepts(?string $country, string $currency, int $amount): bool
    {
        return $this->is_enabled
            && in_array($currency, $this->currencies, true)
            && ($this->countries === null || $this->countries === [] || ($country !== null && in_array($country, $this->countries, true)))
            && $amount >= $this->min_amount
            && ($this->max_amount === null || $amount <= $this->max_amount);
    }
}
