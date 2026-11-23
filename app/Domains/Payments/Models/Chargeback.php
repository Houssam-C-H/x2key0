<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A card chargeback reported by the PSP (spec 9.4). Changed only by `Payments\Actions\ApplyChargeback`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $payment_id
 * @property string $provider
 * @property string $provider_case_id
 * @property int $amount
 * @property int $fee_amount
 * @property string $currency
 * @property string|null $reason_code
 * @property string $status
 * @property int $frozen_amount
 * @property int $recovered_amount
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read Payment $payment
 */
final class Chargeback extends Model
{
    use HasUlids;

    public const string OPEN = 'open';

    public const string WON = 'won';

    public const string LOST = 'lost';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'frozen_amount' => 'integer',
            'recovered_amount' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
