<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Audit\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $public_id
 * @property string $username
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $avatar_path
 * @property string $locale
 * @property string $display_currency
 * @property string|null $country
 * @property string $timezone
 * @property int|null $user_level_id
 * @property UserStatus $status
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property-read UserLevel|null $level
 * @property-read SellerProfile|null $sellerProfile
 * @property-read UserSecurity|null $security
 * @property-read UserPreference|null $preferences
 */
final class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use Auditable;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'username', 'name', 'email', 'password', 'locale', 'display_currency', 'country', 'timezone',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * ULIDs go to `public_id`; the primary key stays a bigint.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return BelongsTo<UserLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'user_level_id');
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * @return HasMany<UserSession, $this>
     */
    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * @return HasMany<LoginEvent, $this>
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    /**
     * @return HasOne<UserSecurity, $this>
     */
    public function security(): HasOne
    {
        return $this->hasOne(UserSecurity::class);
    }

    /**
     * @return HasOne<UserPreference, $this>
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * @return HasMany<UserMessenger, $this>
     */
    public function messengers(): HasMany
    {
        return $this->hasMany(UserMessenger::class);
    }

    /**
     * @return HasMany<Certification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class);
    }

    /**
     * @return HasOne<SellerProfile, $this>
     */
    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    /** Has a store that opens the Seller Center (active, vacation or suspended — spec 3.2 / 6.3). */
    public function isSeller(): bool
    {
        return $this->sellerProfile?->status->opensSellerCenter() === true;
    }

    /**
     * Users holding any staff role (works before the roles are seeded, unlike `role()`).
     *
     * @return Builder<self>
     */
    public static function staffQuery(): Builder
    {
        return self::query()->whereHas('roles', fn (Builder $q) => $q->whereIn('name', StaffRole::values()));
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(StaffRole::values());
    }

    public function hasStaffRole(StaffRole $role): bool
    {
        return $this->hasRole($role->value);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Fortify labels the authenticator entry with the attribute named like the login field ("login"),
     * which is not a column here (users log in with email or username). Use the email.
     */
    public function twoFactorQrCodeUrl(): string
    {
        $secret = Fortify::currentEncrypter()->decrypt((string) $this->two_factor_secret);

        return app(TwoFactorAuthenticationProvider::class)->qrCodeUrl(
            config()->string('x2key.brand.name'),
            $this->email,
            is_string($secret) ? $secret : '',
        );
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function certificationStatus(CertificationType $type): CertificationStatus
    {
        return match ($type) {
            CertificationType::Email => $this->hasVerifiedEmail() ? CertificationStatus::Verified : CertificationStatus::None,
            CertificationType::Phone => $this->hasVerifiedPhone() ? CertificationStatus::Verified : CertificationStatus::None,
            default => $this->certifications->firstWhere('type', $type)->status ?? CertificationStatus::None,
        };
    }

    public function isCertified(CertificationType $type): bool
    {
        return $this->certificationStatus($type) === CertificationStatus::Verified;
    }

    /**
     * Admin panel: active staff only. 2FA is enforced separately (RequireStaffTwoFactor) so staff
     * without 2FA are sent to set it up instead of seeing a bare 403.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active && $this->isStaff();
    }
}
