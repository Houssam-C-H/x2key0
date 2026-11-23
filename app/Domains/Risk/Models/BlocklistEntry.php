<?php

declare(strict_types=1);

namespace App\Domains\Risk\Models;

use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Blocklist (spec 5.17): checkouts matching an active entry are blocked (17.1).
 *
 * @property int $id
 * @property string $type
 * @property string $value
 * @property string|null $reason
 * @property Carbon|null $expires_at
 */
final class BlocklistEntry extends Model
{
    use Auditable;

    public const array TYPES = ['email_domain', 'ip', 'card_bin', 'device', 'payload_hmac'];

    protected $table = 'blocklist';

    protected $fillable = ['type', 'value', 'reason', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function hits(string $type, ?string $value): bool
    {
        return $value !== null && $value !== '' && self::query()->active()->where('type', $type)->where('value', mb_strtolower($value))->exists();
    }
}
