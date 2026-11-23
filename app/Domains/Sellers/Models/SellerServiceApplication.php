<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permission to sell in one game×category (spec 6.9). Status changes through
 * ApplyForService / ReviewServiceApplication only.
 *
 * @property int $id
 * @property int $seller_profile_id
 * @property int $game_category_id
 * @property ServiceApplicationStatus $status
 * @property string|null $reject_reason
 * @property Carbon|null $audited_at
 * @property int|null $audited_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SellerProfile $profile
 * @property-read GameCategory $gameCategory
 * @property-read User|null $auditor
 */
final class SellerServiceApplication extends Model
{
    use Auditable;

    protected $fillable = ['seller_profile_id', 'game_category_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceApplicationStatus::class,
            'audited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return BelongsTo<GameCategory, $this>
     */
    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }
}
