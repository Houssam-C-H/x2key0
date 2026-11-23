<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money returned to the buyer (spec 5.7): one row per source — the card part back to the PSP
 * (`original_method`) and/or the credits part (`credits`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int|null $payment_id
 * @property string $destination
 * @property int $amount
 * @property string $currency
 * @property string $reason
 * @property string $status
 * @property string|null $provider_refund_id
 * @property string $initiated_by_type
 * @property int|null $initiated_by_id
 * @property Carbon $created_at
 */
final class Refund extends Model
{
    use HasUlids;

    public const string ORIGINAL = 'original_method';

    public const string CREDITS = 'credits';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
