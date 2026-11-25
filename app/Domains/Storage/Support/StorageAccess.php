<?php

declare(strict_types=1);

namespace App\Domains\Storage\Support;

use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Exceptions\StorageException;

/** Staff can switch a seller's Storage off (ADR-034); every Storage write checks it. */
final class StorageAccess
{
    public static function assertEnabled(SellerProfile $profile): void
    {
        if (! $profile->storage_enabled) {
            throw StorageException::because('storage.error.disabled');
        }
    }
}
