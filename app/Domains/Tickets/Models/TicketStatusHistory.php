<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Models;

use App\Domains\Tickets\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only row written by TicketStateMachine for every transition.
 *
 * @property int $id
 * @property int $ticket_id
 * @property TicketStatus|null $from_status
 * @property TicketStatus $to_status
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class TicketStatusHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'ticket_status_history';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Ticket history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Ticket history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_status' => TicketStatus::class, 'to_status' => TicketStatus::class];
    }
}
