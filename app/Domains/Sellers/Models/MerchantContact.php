<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ContactChannel;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant-services channel assigned to a seller (spec 5.3, Merchant Services Contacts).
 *
 * @property int $id
 * @property int $seller_profile_id
 * @property int|null $staff_user_id
 * @property ContactChannel $channel
 * @property string $value
 * @property string|null $hours
 * @property bool $is_primary
 * @property-read User|null $staff
 */
final class MerchantContact extends Model
{
    use Auditable;

    protected $fillable = ['seller_profile_id', 'staff_user_id', 'channel', 'value', 'hours', 'is_primary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ContactChannel::class,
            'is_primary' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
