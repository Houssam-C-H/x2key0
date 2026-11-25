<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageItem;
use App\Domains\Storage\Support\StorageAccess;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Seller actions on items (spec 8.1 / Recycle Station): mark invalid, move to the Recycle Station, restore,
 * purge for good. Items that belong to orders are never touched. Each batch is audited (ids and counts only).
 */
final class ManageStorageItems
{
    public const array OPERATIONS = ['invalid', 'recycle', 'restore', 'purge'];

    /** Allowed source statuses per operation. */
    private const array FROM = [
        'invalid' => [StorageItemStatus::Available],
        'recycle' => [StorageItemStatus::Available, StorageItemStatus::Invalid],
        'restore' => [StorageItemStatus::Recycled],
        'purge' => [StorageItemStatus::Recycled],
    ];

    public function __construct(
        private readonly RefreshStorageStock $refresh,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $itemIds
     */
    public function handle(SellerProfile $profile, User $actor, string $operation, array $itemIds): int
    {
        StorageAccess::assertEnabled($profile);
        if ($profile->status === SellerStatus::Suspended) {
            throw StorageException::because('seller.error.suspended');
        }

        if (! isset(self::FROM[$operation])) {
            throw StorageException::because('storage.error.operation');
        }

        $from = array_map(static fn (StorageItemStatus $s): string => $s->value, self::FROM[$operation]);
        $ids = array_slice(array_values(array_unique($itemIds)), 0, 500);

        [$count, $groupIds] = DB::transaction(function () use ($profile, $actor, $operation, $from, $ids): array {
            $items = StorageItem::query()
                ->where('seller_profile_id', $profile->id)
                ->whereIn('id', $ids)
                ->whereIn('status', $from)
                ->lockForUpdate()
                ->get(['id', 'storage_group_id']);

            $affected = $items->pluck('id')->all();
            $query = StorageItem::query()->whereIn('id', $affected);

            if ($operation === 'purge') {
                $query->delete();
            } else {
                $query->update(match ($operation) {
                    'invalid' => ['status' => StorageItemStatus::Invalid->value, 'updated_at' => now()],
                    'recycle' => ['status' => StorageItemStatus::Recycled->value, 'recycled_at' => now(), 'updated_at' => now()],
                    default => ['status' => StorageItemStatus::Available->value, 'recycled_at' => null, 'updated_at' => now()],
                });
            }

            if ($affected !== []) {
                $this->audit->log("storage_items.{$operation}", null, null, ['item_ids' => $affected], $actor);
            }

            return [count($affected), array_values(array_unique($items->map(static fn (StorageItem $i): int => $i->storage_group_id)->all()))];
        });

        foreach (StorageGroup::query()->whereIn('id', $groupIds)->get() as $group) {
            $this->refresh->handle($group);
        }

        return $count;
    }
}
