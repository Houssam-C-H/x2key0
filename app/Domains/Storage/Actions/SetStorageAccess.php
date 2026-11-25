<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Models\StorageGroup;
use App\Shared\Audit\AuditLogger;

/**
 * Staff switch a seller's Storage on or off (ADR-034). Off: no Storage writes, every group counts as empty so
 * auto-delivery offers go out of stock (keys stay encrypted, untouched). On: stock is recounted and offers come
 * back. Orders whose keys were reserved before the switch still deliver. Audited through the profile change.
 */
final class SetStorageAccess
{
    public function __construct(
        private readonly RefreshStorageStock $refresh,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(SellerProfile $profile, bool $enabled, User $staff, string $reason): SellerProfile
    {
        if ($profile->storage_enabled !== $enabled) {
            $profile->forceFill(['storage_enabled' => $enabled])->save();
            $this->audit->log($enabled ? 'seller.storage_enabled' : 'seller.storage_disabled', $profile, after: ['reason' => mb_substr($reason, 0, 500)], actor: $staff);
            Notifier::notify($profile->user_id, $enabled ? 'storage_enabled' : 'storage_disabled', 'merchant', ['href' => '/seller/storage']);
        }

        $this->refreshGroups($profile);

        return $profile->refresh();
    }

    /** Recount every group of the seller (idempotent; also used after a direct edit in the admin form). */
    public function refreshGroups(SellerProfile $profile): void
    {
        StorageGroup::query()->where('seller_profile_id', $profile->id)->each(fn (StorageGroup $g) => $this->refresh->handle($g));
    }
}
