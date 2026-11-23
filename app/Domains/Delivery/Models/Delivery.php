<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use App\Domains\Orders\Models\OrderItem;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One delivery of N units of an order item (spec 5.8): from Storage (system) or by the seller.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $quantity
 * @property string $method
 * @property string $delivered_by_type
 * @property int|null $delivered_by_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read OrderItem $item
 */
final class Delivery extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<DeliverySecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(DeliverySecret::class);
    }

    /**
     * @return HasMany<DeliveryEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(DeliveryEvidence::class);
    }

    /**
     * @return BelongsToMany<StorageItem, $this>
     */
    public function storageItems(): BelongsToMany
    {
        return $this->belongsToMany(StorageItem::class, 'delivery_storage_items');
    }
}
