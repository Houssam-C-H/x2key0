<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner, logo, video or ready-made copy affiliates can use (spec Part 15 "Creative Assets"), by theme, size and
 * language. Managed in the admin; files live on the public disk.
 *
 * @property int $id
 * @property array<string, string> $title
 * @property string $type
 * @property string $theme
 * @property string|null $size
 * @property string|null $locale
 * @property string|null $file_path
 * @property string|null $copy_text
 * @property bool $is_active
 */
final class CreativeAsset extends Model
{
    use HasTranslations;

    public const array TYPES = ['banner', 'logo', 'video', 'copy'];

    protected $fillable = ['title', 'type', 'theme', 'size', 'locale', 'file_path', 'copy_text', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'is_active' => 'boolean'];
    }
}
