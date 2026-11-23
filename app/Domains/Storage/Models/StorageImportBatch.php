<?php

declare(strict_types=1);

namespace App\Domains\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Duplicate report of one import (spec 5.5 / 21: "import with duplicate report").
 *
 * @property int $id
 * @property int $storage_group_id
 * @property int|null $uploaded_by
 * @property string $source
 * @property int $count_total
 * @property int $count_duplicates
 * @property int $count_invalid
 * @property int $count_flagged
 * @property int $count_accepted
 * @property Carbon $created_at
 */
final class StorageImportBatch extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = [
        'storage_group_id', 'uploaded_by', 'source', 'count_total', 'count_duplicates', 'count_invalid', 'count_flagged', 'count_accepted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count_total' => 'integer',
            'count_duplicates' => 'integer',
            'count_invalid' => 'integer',
            'count_flagged' => 'integer',
            'count_accepted' => 'integer',
        ];
    }
}
