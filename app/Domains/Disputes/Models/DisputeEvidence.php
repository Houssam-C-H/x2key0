<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Evidence file of a dispute, on the private disk with its SHA-256 (served to the parties and staff only).
 *
 * @property int $id
 * @property int $dispute_id
 * @property int $uploaded_by
 * @property DisputeRole $uploaded_by_role
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string|null $description
 * @property string $scan_status
 * @property Carbon $created_at
 */
final class DisputeEvidence extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'dispute_evidence';

    protected $guarded = ['*'];

    protected $hidden = ['file_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_by_role' => DisputeRole::class,
            'size' => 'integer',
        ];
    }
}
