<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One paid marketing e-mail send (spec 14.3): recipients × unit price, billed the next day from the seller's
 * Available balance (`MARKETING_FEE`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $seller_profile_id
 * @property string $type
 * @property list<int> $offer_ids
 * @property int|null $coupon_id
 * @property int $recipient_count
 * @property int $unit_price_millicents
 * @property int $total_amount
 * @property string $status
 * @property Carbon $sent_at
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 */
final class MarketingEmail extends Model
{
    use HasUlids;

    public const array TYPES = ['new_product', 'limited_discount', 'loyal_customer', 'clearance'];

    protected $guarded = ['*'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['offer_ids' => 'array', 'sent_at' => 'datetime', 'settled_at' => 'datetime'];
    }
}
