<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\MessengerType;
use Illuminate\Database\Eloquent\Model;

/**
 * Messenger handles shown to staff / merchant managers only — never to counterparties (no off-platform trading).
 *
 * @property int $id
 * @property int $user_id
 * @property MessengerType $type
 * @property string $handle
 */
final class UserMessenger extends Model
{
    protected $fillable = ['user_id', 'type', 'handle'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => MessengerType::class];
    }
}
