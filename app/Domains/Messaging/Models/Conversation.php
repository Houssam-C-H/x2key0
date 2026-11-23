<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat between a buyer and a seller: one per order (created at `paid`) or pre-sale from a store / offer
 * (spec 12.1). Members are the rows in `conversation_participants`.
 *
 * @property int $id
 * @property string $public_id
 * @property string $type
 * @property int|null $order_id
 * @property int $buyer_id
 * @property int|null $seller_profile_id
 * @property Carbon|null $last_message_at
 * @property-read User $buyer
 * @property-read SellerProfile|null $seller
 * @property-read Order|null $order
 */
final class Conversation extends Model
{
    use HasUlids;

    public const string ORDER = 'order';

    public const string PRE_SALE = 'pre_sale';

    protected $fillable = ['type', 'order_id', 'buyer_id', 'seller_profile_id', 'last_message_at'];

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
        return ['last_message_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }
}
