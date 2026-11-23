<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * File attached to a ticket message, on the private disk with its SHA-256 (scan pending until ClamAV exists).
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $ticket_message_id
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $scan_status
 * @property Carbon $created_at
 */
final class TicketAttachment extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['file_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }
}
