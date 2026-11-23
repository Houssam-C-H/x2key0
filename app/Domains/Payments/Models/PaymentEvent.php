<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider webhook (or sync result) as received (spec 5.7 / 9.3). Unique per (provider, event id), so a
 * replayed webhook is stored once and processed at most once.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property bool $signature_valid
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
final class PaymentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'provider_event_id', 'type', 'payload', 'signature_valid', 'received_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
