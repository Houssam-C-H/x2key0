<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * A footer keyword link (spec 1.3 #12 "SEO keyword row").
 *
 * @property int $id
 * @property array<string, string> $label
 * @property string $url
 * @property int $sort
 * @property bool $is_enabled
 */
final class SeoLinkRow extends Model
{
    use HasTranslations;

    protected $fillable = ['label', 'url', 'sort', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['label' => 'array', 'is_enabled' => 'boolean', 'sort' => 'integer'];
    }
}
