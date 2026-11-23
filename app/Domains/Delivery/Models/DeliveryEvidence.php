<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Proof of a manual delivery (screenshot, spec 8.2), stored on the private disk.
 *
 * @property int $id
 * @property int $delivery_id
 * @property string $file_path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $scan_status
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 */
final class DeliveryEvidence extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'delivery_evidence';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
