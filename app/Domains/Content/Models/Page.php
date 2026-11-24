<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A CMS page (spec 5.17: about, contact, legal and policy texts) at `/{locale}/page/{slug}`; pages with a
 * `footer_column` are listed in that footer column.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string|null $footer_column
 * @property int $sort
 * @property bool $is_published
 * @property Carbon $updated_at
 */
final class Page extends Model
{
    use Auditable;
    use HasTranslations;

    public const array FOOTER_COLUMNS = ['company', 'support', 'legal', 'policies'];

    protected $fillable = ['slug', 'title', 'body', 'footer_column', 'sort', 'is_published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'is_published' => 'boolean', 'sort' => 'integer'];
    }

    public function path(string $locale): string
    {
        return "/{$locale}/page/{$this->slug}";
    }
}
