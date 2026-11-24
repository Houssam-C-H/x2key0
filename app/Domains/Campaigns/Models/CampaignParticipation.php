<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seller's application to a campaign with some offers (spec 14.2); staff approve or reject it.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $seller_profile_id
 * @property list<int> $offer_ids
 * @property string $status
 * @property string|null $reject_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read Campaign $campaign
 * @property-read SellerProfile $seller
 */
final class CampaignParticipation extends Model
{
    public const string APPLIED = 'applied';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string WITHDRAWN = 'withdrawn';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['offer_ids' => 'array', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }
}
