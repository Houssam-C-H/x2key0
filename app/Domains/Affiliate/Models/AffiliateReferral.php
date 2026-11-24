<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user who signed up through an affiliate (one per user). Orders earn commission until `attribution_expires_at`.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $referred_user_id
 * @property int|null $affiliate_link_id
 * @property Carbon|null $first_click_at
 * @property Carbon $signed_up_at
 * @property Carbon $attribution_expires_at
 * @property-read User $user
 * @property-read Affiliate $affiliate
 */
final class AffiliateReferral extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['first_click_at' => 'datetime', 'signed_up_at' => 'datetime', 'attribution_expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
