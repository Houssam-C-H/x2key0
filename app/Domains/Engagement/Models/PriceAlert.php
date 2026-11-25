<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Models;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Tell me when this is below $X" on a product or a game×category (Engagement\Actions\PriceAlerts).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $product_id
 * @property int|null $game_category_id
 * @property int $target_amount USD cents
 * @property string $currency
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Product|null $product
 * @property-read GameCategory|null $gameCategory
 */
final class PriceAlert extends Model
{
    protected $fillable = ['user_id', 'product_id', 'game_category_id', 'target_amount', 'currency', 'notified_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['target_amount' => 'integer', 'notified_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<GameCategory, $this>
     */
    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }
}
