<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $slug
 * @property array<string, string> $name
 * @property string|null $publisher
 * @property string|null $cover_path
 * @property string|null $icon_path
 * @property bool $is_hot
 * @property bool $is_new
 * @property bool $is_enabled
 * @property Carbon|null $first_listed_at
 * @property list<string>|null $search_aliases
 * @property int $offer_count_cached
 * @property int $product_count_cached
 * @property Carbon $created_at
 * @property-read Collection<int, GameCategory> $categories
 */
final class Game extends Model
{
    use Auditable;

    /** @use HasFactory<GameFactory> */
    use HasFactory;

    use HasTranslations;

    /** First URL segments that can never be game slugs (they are other public routes). */
    public const array RESERVED_SLUGS = [
        'login', 'register', 'forgot-password', 'reset-password', 'games', 'catalog', 'search',
        'news', 'help', 'affiliate', 'page', 'store', 'offer', 'sitemap', 'api', 'admin',
    ];

    protected $fillable = [
        'slug', 'name', 'publisher', 'cover_path', 'icon_path', 'hero_path',
        'is_hot', 'is_new', 'is_enabled', 'first_listed_at', 'search_aliases',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_hot' => 'boolean',
            'is_new' => 'boolean',
            'is_enabled' => 'boolean',
            'first_listed_at' => 'datetime',
            'search_aliases' => 'array',
            'offer_count_cached' => 'integer',
            'product_count_cached' => 'integer',
        ];
    }

    protected static function newFactory(): GameFactory
    {
        return GameFactory::new();
    }

    /**
     * @return HasMany<GameCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(GameCategory::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /** Index letter for the A–Z Game Index ("#" for digits and symbols). */
    /** Square picture for lists and menus: the icon, else the cover (null → generated art on the site). */
    public function iconUrl(): ?string
    {
        return self::publicUrl($this->icon_path) ?? self::publicUrl($this->cover_path);
    }

    /** Wide picture for cards: the cover, else the icon. */
    public function coverUrl(): ?string
    {
        return self::publicUrl($this->cover_path) ?? self::publicUrl($this->icon_path);
    }

    /** Banner for the game page (null → brand gradient). */
    public function heroUrl(): ?string
    {
        return self::publicUrl($this->hero_path);
    }

    /** Stored path → public URL; absolute URLs and site paths are kept. */
    public static function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http') || str_starts_with($path, '/')) {
            return $path;
        }

        // Local public disk: a same-site path (works whatever APP_URL says, also behind proxies); other disks
        // (S3, CDN) give their absolute URL.
        return config('filesystems.disks.public.driver') === 'local' ? '/storage/'.ltrim($path, '/') : Storage::disk('public')->url($path);
    }

    public function indexLetter(): string
    {
        $first = mb_strtoupper(mb_substr($this->translate('name', 'en'), 0, 1));

        return preg_match('/^[A-Z]$/', $first) === 1 ? $first : '#';
    }
}
