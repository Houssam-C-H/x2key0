<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the user's inbox (spec 5.9): a system notification, or the read/star state of an announcement.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $announcement_id
 * @property string|null $notification_type
 * @property string $category
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $starred_at
 * @property Carbon $created_at
 */
final class InboxItem extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'user_inbox';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'starred_at' => 'datetime'];
    }
}
