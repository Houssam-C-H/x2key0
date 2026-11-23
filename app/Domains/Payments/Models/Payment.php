<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment attempt for an order (spec 5.7): a PSP payment, or `provider = credits` for the credits part.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $order_id
 * @property string $purpose
 * @property string $provider
 * @property string $method_code
 * @property string|null $provider_payment_id
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 * @property int $fee_amount
 * @property bool $three_ds
 * @property string|null $card_bin
 * @property string|null $card_last4
 * @property string|null $card_country
 * @property string|null $redirect_url
 * @property string|null $failure_reason
 * @property Carbon|null $captured_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order|null $order
 */
final class Payment extends Model
{
    use HasUlids;

    public const string CREDITS = 'credits';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'three_ds' => 'boolean',
            'captured_at' => 'datetime',
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
}
