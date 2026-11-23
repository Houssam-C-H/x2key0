<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property string|null $flag
 * @property int $sort
 */
final class Region extends Model
{
    use Auditable;
    use HasTranslations;

    protected $fillable = ['code', 'name', 'flag', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'sort' => 'integer'];
    }
}
