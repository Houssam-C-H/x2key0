<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 * @property bool $muted
 */
final class Participant extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'conversation_participants';

    protected $fillable = ['conversation_id', 'user_id', 'last_read_message_id', 'muted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['muted' => 'boolean'];
    }
}
