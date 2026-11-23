<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Models;

use App\Domains\Tickets\Enums\TicketTopic;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Prepared staff answer (spec Part 11), per locale; staff pick one and edit it before sending.
 *
 * @property int $id
 * @property string $title
 * @property TicketTopic|null $topic
 * @property array<string, string> $body
 * @property bool $is_active
 */
final class CannedReply extends Model
{
    use Auditable;
    use HasTranslations;

    protected $fillable = ['title', 'topic', 'body', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['topic' => TicketTopic::class, 'body' => 'array', 'is_active' => 'boolean'];
    }
}
