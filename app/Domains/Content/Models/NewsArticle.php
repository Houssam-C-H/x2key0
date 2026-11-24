<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use App\Domains\Catalog\Models\Game;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Game News (spec 5.17, Part 20 `/news`): published when `status = published` and `published_at` has passed.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $title
 * @property array<string, string>|null $excerpt
 * @property array<string, string> $body
 * @property string|null $cover_path
 * @property int|null $game_id
 * @property Carbon|null $published_at
 * @property int|null $author_id
 * @property string $status
 * @property Carbon $updated_at
 * @property-read Game|null $game
 */
final class NewsArticle extends Model
{
    use Auditable;
    use HasTranslations;

    protected $fillable = ['slug', 'title', 'excerpt', 'body', 'cover_path', 'game_id', 'published_at', 'author_id', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'excerpt' => 'array', 'body' => 'array', 'published_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function path(string $locale): string
    {
        return "/{$locale}/news/{$this->slug}";
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
