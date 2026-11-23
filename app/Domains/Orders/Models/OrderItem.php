<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $offer_id
 * @property int|null $product_id
 * @property int $quantity
 * @property int $unit_price_amount
 * @property int $delivered_qty
 * @property int $refunded_qty
 * @property int $reserved_manual_qty
 * @property array<string, mixed> $snapshot
 * @property-read Order $order
 * @property-read Offer $offer
 */
final class OrderItem extends Model
{
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_amount' => 'integer',
            'delivered_qty' => 'integer',
            'refunded_qty' => 'integer',
            'reserved_manual_qty' => 'integer',
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
    }
}
