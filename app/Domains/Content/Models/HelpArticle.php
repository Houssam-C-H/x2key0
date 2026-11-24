<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Help center article (spec 5.17, Part 20 `/help`), grouped by section.
 *
 * @property int $id
 * @property string $slug
 * @property string $section
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property int $sort
 * @property bool $is_published
 */
final class HelpArticle extends Model
{
    use Auditable;
    use HasTranslations;

    public const array SECTIONS = ['how_to_buy', 'how_to_sell', 'how_to_cancel', 'payments', 'account', 'faq'];

    protected $fillable = ['slug', 'section', 'title', 'body', 'sort', 'is_published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'is_published' => 'boolean', 'sort' => 'integer'];
    }

    public function path(string $locale): string
    {
        return "/{$locale}/help/{$this->slug}";
    }
}
