<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Storage › Delivery Logs (spec 1.7 / 5.5): one row per storage item sent by auto delivery, or per failure.
 *
 * @property int $id
 * @property int|null $storage_item_id
 * @property int $order_item_id
 * @property int $offer_id
 * @property int $seller_profile_id
 * @property Carbon $delivered_at
 * @property string $result
 * @property string|null $error
 */
final class AutoDeliveryLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }
}
