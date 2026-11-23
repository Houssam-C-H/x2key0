<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageGroup;

/**
 * Storage Category: create a group or change its name / low-stock threshold (spec 5.5). The item type is
 * fixed once the group holds items, so linked offers keep delivering the right kind of secret.
 */
final class SaveStorageGroup
{
    public function __construct(private readonly RefreshStorageStock $refresh) {}

    public function create(SellerProfile $profile, string $name, StorageItemType $type, int $threshold): StorageGroup
    {
        self::assertCanWrite($profile);
        $name = self::name($profile, $name);

        return StorageGroup::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'item_type' => $type,
            'low_stock_threshold' => $threshold,
        ])->refresh();
    }

    public function update(StorageGroup $group, string $name, StorageItemType $type, int $threshold): StorageGroup
    {
        $group->loadMissing('seller');
        self::assertCanWrite($group->seller);

        if ($type !== $group->item_type && $group->items()->exists()) {
            throw StorageException::because('storage.error.type_locked');
        }

        $group->fill([
            'name' => self::name($group->seller, $name, $group->id),
            'item_type' => $type,
            'low_stock_threshold' => $threshold,
        ])->save();

        return $this->refresh->handle($group);
    }

    private static function name(SellerProfile $profile, string $name, ?int $ignoreId = null): string
    {
        $name = trim($name);

        $taken = StorageGroup::query()
            ->where('seller_profile_id', $profile->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw StorageException::because('storage.error.name_taken');
        }

        return $name;
    }

    private static function assertCanWrite(SellerProfile $profile): void
    {
        if ($profile->status === SellerStatus::Suspended) {
            throw StorageException::because('seller.error.suspended');
        }
    }
}
