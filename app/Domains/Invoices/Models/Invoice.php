<?php

declare(strict_types=1);

namespace App\Domains\Invoices\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An issued document (Part 16): monthly service invoice (platform fees) or withdrawal voucher. Never changed
 * once issued; numbers are sequential per prefix and year.
 *
 * @property int $id
 * @property string $number
 * @property string $type
 * @property int $user_id
 * @property string $source_type
 * @property int|null $source_id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property list<array{label: string, amount: int}> $lines
 * @property array<string, mixed> $billed_to
 * @property int $subtotal
 * @property int $tax
 * @property int $total
 * @property string $currency
 * @property Carbon $issued_at
 * @property-read User $user
 */
final class Invoice extends Model
{
    public const string SERVICE = 'service_invoice';

    public const string VOUCHER = 'withdrawal_voucher';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Issued invoices are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Issued invoices are immutable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'billed_to' => 'array',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function path(): string
    {
        return "/invoices/view/{$this->number}";
    }
}
