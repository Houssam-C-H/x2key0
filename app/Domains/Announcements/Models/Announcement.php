<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Models;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A platform announcement (spec 5.9 / 12.2), written by staff. Users see it through the announcement
 * centre without a per-user row until they read or star it.
 *
 * @property int $id
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string $audience
 * @property array<string, mixed>|null $segment_query
 * @property string $category
 * @property Carbon $publish_at
 * @property Carbon|null $expires_at
 * @property int|null $created_by
 */
final class Announcement extends Model
{
    use Auditable;
    use HasTranslations;

    public const array AUDIENCES = ['all', 'buyers', 'sellers'];

    public const array CATEGORIES = ['todo', 'announcement', 'order', 'dispute', 'platform_service', 'merchant'];

    protected $fillable = ['title', 'body', 'audience', 'segment_query', 'category', 'publish_at', 'expires_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'array',
            'body' => 'array',
            'segment_query' => 'array',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Announcements this user should see now: published, not expired, and addressed to them.
     * Everyone is a buyer; sellers (store opens the Seller Center) also see `sellers`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $audiences = ['all', 'buyers'];
        if ($user->isSeller()) {
            $audiences[] = 'sellers';
        }

        $query->where('publish_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('audience', $audiences);
    }
}
