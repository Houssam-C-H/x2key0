<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotional video an affiliate submitted (spec Part 15). Staff approve it with a reward (AFFILIATE_VIDEO_REWARD)
 * or reject it with a note.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property string $platform
 * @property string $url
 * @property int $views_reported
 * @property string $status
 * @property int $reward_amount
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property-read Affiliate $affiliate
 */
final class AffiliateVideoRecord extends Model
{
    public const array PLATFORMS = ['youtube', 'tiktok', 'twitch', 'other'];

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['views_reported' => 'integer', 'reward_amount' => 'integer', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
