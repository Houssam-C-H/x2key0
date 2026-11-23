<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Store page extras (spec 5.3). The banner lives on seller_profiles (no duplicate column here).
 *
 * @property int $seller_profile_id
 * @property string|null $video_path
 * @property list<array{code: string, level: string}>|null $languages
 * @property list<int>|null $featured_offer_ids
 */
final class SellerStoreMedia extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'seller_profile_id';

    protected $table = 'seller_store_media';

    protected $fillable = ['seller_profile_id', 'video_path', 'languages', 'featured_offer_ids'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'featured_offer_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }
}
