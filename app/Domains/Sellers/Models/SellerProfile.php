<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Database\Factories\SellerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A user's store (spec 5.3). `status` changes only through SellerProfileStateMachine,
 * so it is not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $store_name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property array<string, string>|null $bio
 * @property string|null $country
 * @property SellerStatus $status
 * @property string|null $status_reason
 * @property int|null $seller_level_id
 * @property bool $level_locked
 * @property bool $payouts_enabled
 * @property int|null $merchant_manager_id
 * @property array<string, string|null>|null $auto_reply
 * @property array<string, string>|null $store_notice
 * @property Carbon|null $vacation_until
 * @property Carbon|null $activated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read SellerLevel|null $level
 * @property-read User|null $merchantManager
 * @property-read SellerStats|null $stats
 * @property-read SellerStoreMedia|null $media
 */
final class SellerProfile extends Model
{
    use Auditable;

    /** @use HasFactory<SellerProfileFactory> */
    use HasFactory;

    use HasTranslations;

    protected $fillable = [
        'user_id', 'store_name', 'slug', 'logo_path', 'banner_path', 'bio', 'country',
        'seller_level_id', 'level_locked', 'payouts_enabled', 'merchant_manager_id',
        'auto_reply', 'store_notice',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'bio' => 'array',
            'auto_reply' => 'array',
            'store_notice' => 'array',
            'level_locked' => 'boolean',
            'payouts_enabled' => 'boolean',
            'vacation_until' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SellerProfileFactory
    {
        return SellerProfileFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SellerLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(SellerLevel::class, 'seller_level_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function merchantManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_manager_id');
    }

    /**
     * @return HasOne<SellerStats, $this>
     */
    public function stats(): HasOne
    {
        return $this->hasOne(SellerStats::class);
    }

    /**
     * @return HasOne<SellerStoreMedia, $this>
     */
    public function media(): HasOne
    {
        return $this->hasOne(SellerStoreMedia::class);
    }

    /**
     * @return HasMany<SellerServiceApplication, $this>
     */
    public function serviceApplications(): HasMany
    {
        return $this->hasMany(SellerServiceApplication::class);
    }

    /**
     * @return HasMany<MerchantContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(MerchantContact::class);
    }

    /**
     * @return HasMany<SellerStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(SellerStatusChange::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->whereIn('status', [SellerStatus::Active->value, SellerStatus::Vacation->value]);
    }

    /** Passed for this game×category: required to list offers there (6.9). */
    public function canSellIn(int $gameCategoryId): bool
    {
        return $this->serviceApplications()
            ->where('game_category_id', $gameCategoryId)
            ->where('status', ServiceApplicationStatus::Passed)
            ->exists();
    }

    public function path(string $locale): string
    {
        return "/{$locale}/store/{$this->slug}";
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path === null ? null : Storage::disk('public')->url($this->banner_path);
    }
}
