<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recently viewed products and offers (spec 1.3 #2 / 5.17), for a user or a guest cookie token.
 * One row per viewed item (re-viewing moves it to the front); trimmed to `browsing_history_limit`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $guest_token
 * @property int|null $product_id
 * @property int|null $offer_id
 * @property Carbon $viewed_at
 * @property-read Product|null $product
 * @property-read Offer|null $offer
 */
final class BrowsingHistory extends Model
{
    public $timestamps = false;

    protected $table = 'browsing_history';

    protected $fillable = ['user_id', 'guest_token', 'product_id', 'offer_id', 'viewed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
