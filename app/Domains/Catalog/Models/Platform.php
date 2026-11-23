<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $icon_path
 * @property int $sort
 */
final class Platform extends Model
{
    use Auditable;

    protected $fillable = ['slug', 'name', 'icon_path', 'sort'];
}
