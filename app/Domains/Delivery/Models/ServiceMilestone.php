<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Progress step of a service order (boosting, spec 8.2); `percent` is the cumulative progress.
 *
 * @property int $id
 * @property int $order_item_id
 * @property string $title
 * @property int $percent
 * @property string $status
 * @property string|null $note
 * @property Carbon $created_at
 */
final class ServiceMilestone extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['percent' => 'integer'];
    }
}
