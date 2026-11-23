<?php

declare(strict_types=1);

namespace App\Domains\Storage\Models;

use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Vault\SealedSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One encrypted secret (key, code, account…) in a storage group (spec 5.5). The payload is only ever
 * opened by RevealStorageItem (audited) and, from M8, by auto delivery. The sealed columns are hidden so
 * they never reach JSON, Inertia props or audit rows.
 *
 * @property int $id
 * @property int $storage_group_id
 * @property int $seller_profile_id
 * @property StorageItemStatus $status
 * @property string $payload_ciphertext
 * @property string $payload_dek_wrapped
 * @property string $payload_hmac
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $flagged_at
 * @property int|null $reserved_for_order_item_id
 * @property Carbon|null $reserved_until
 * @property int|null $assigned_order_item_id
 * @property int|null $import_batch_id
 * @property Carbon|null $recycled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read StorageGroup $group
 */
final class StorageItem extends Model
{
    protected $fillable = [
        'storage_group_id', 'seller_profile_id', 'status', 'payload_ciphertext', 'payload_dek_wrapped', 'payload_hmac',
        'meta', 'import_batch_id',
    ];

    protected $hidden = ['payload_ciphertext', 'payload_dek_wrapped', 'payload_hmac'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StorageItemStatus::class,
            'meta' => 'array',
            'flagged_at' => 'datetime',
            'reserved_until' => 'datetime',
            'recycled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StorageGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StorageGroup::class, 'storage_group_id');
    }

    public function sealed(): SealedSecret
    {
        return new SealedSecret($this->payload_ciphertext, $this->payload_dek_wrapped);
    }
}
