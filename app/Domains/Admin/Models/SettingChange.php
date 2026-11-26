<?php

declare(strict_types=1);

namespace App\Domains\Admin\Models;

use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $key
 * @property mixed $old_value
 * @property mixed $new_value
 * @property bool $is_reset
 * @property string $reason
 * @property string $status
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $requester
 * @property-read User|null $decider
 */
final class SettingChange extends Model
{
    public const string PENDING = 'pending';

    public const string APPLIED = 'applied';

    public const string REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'json',
            'new_value' => 'json',
            'is_reset' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
