<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageImportBatch;
use App\Domains\Storage\Models\StorageItem;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Storage import (spec 8.1): fingerprint every normalised item with HMAC-SHA256(pepper), refuse duplicates
 * inside the batch and against the seller's whole vault, flag and freeze cross-seller duplicates (both
 * sides), seal the rest with per-item data keys, and report the counts. Imports of one seller are
 * serialised by locking the seller row, so the duplicate check cannot race.
 *
 * Nothing secret is logged: the audit row only carries counts.
 */
final class ImportStorageItems
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly RefreshStorageStock $refresh,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{items: list<array{plaintext: string, normalised: string}>, invalid: int}  $parsed
     */
    public function handle(StorageGroup $group, User $actor, string $source, #[SensitiveParameter] array $parsed): StorageImportBatch
    {
        $group->loadMissing('seller');
        $profile = $group->seller;

        if ($profile->status === SellerStatus::Suspended) {
            throw StorageException::because('seller.error.suspended');
        }

        $total = count($parsed['items']) + $parsed['invalid'];
        if ($total === 0) {
            throw StorageException::because('storage.error.empty');
        }
        if ($total > $this->settings->int('storage_import_max_items')) {
            throw StorageException::because('storage.error.too_many', ['max' => $this->settings->int('storage_import_max_items')]);
        }

        $frozenGroups = [];

        $batch = DB::transaction(function () use ($group, $profile, $actor, $source, $parsed, $total, &$frozenGroups): StorageImportBatch {
            SellerProfile::query()->lockForUpdate()->findOrFail($profile->id);

            // 1. Fingerprints, and duplicates inside this batch.
            $candidates = [];
            $duplicates = 0;
            foreach ($parsed['items'] as $item) {
                $hmac = $this->vault->fingerprint($item['normalised']);
                if (isset($candidates[$hmac])) {
                    $duplicates++;

                    continue;
                }
                $candidates[$hmac] = $item['plaintext'];
            }

            // 2. Already in this seller's vault (any group, any status).
            foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
                $existing = StorageItem::query()->where('seller_profile_id', $profile->id)->whereIn('payload_hmac', $chunk)->pluck('payload_hmac');
                foreach ($existing as $hmac) {
                    if (! is_string($hmac)) {
                        continue;
                    }
                    unset($candidates[$hmac]);
                    $duplicates++;
                }
            }

            // 3. Held by another seller: accept but freeze both sides for risk review (Part 13).
            $crossSeller = [];
            foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
                $others = StorageItem::query()->where('seller_profile_id', '!=', $profile->id)->whereIn('payload_hmac', $chunk)->get(['id', 'payload_hmac', 'storage_group_id', 'flagged_at']);
                foreach ($others as $other) {
                    $crossSeller[$other->payload_hmac] = true;
                    $frozenGroups[$other->storage_group_id] = true;
                    if ($other->flagged_at === null) {
                        $other->flagged_at = now();
                        $other->save();
                    }
                }
            }

            $batch = StorageImportBatch::query()->create([
                'storage_group_id' => $group->id,
                'uploaded_by' => $actor->id,
                'source' => $source,
                'count_total' => $total,
                'count_duplicates' => $duplicates,
                'count_invalid' => $parsed['invalid'],
                'count_flagged' => count($crossSeller),
                'count_accepted' => count($candidates),
            ]);

            // 4. Seal and store.
            $now = now();
            $rows = [];
            foreach ($candidates as $hmac => $plaintext) {
                $sealed = $this->vault->seal($plaintext);
                $rows[] = [
                    'storage_group_id' => $group->id,
                    'seller_profile_id' => $profile->id,
                    'status' => StorageItemStatus::Available->value,
                    'payload_ciphertext' => $sealed->ciphertext,
                    'payload_dek_wrapped' => $sealed->wrappedKey,
                    'payload_hmac' => $hmac,
                    'meta' => null,
                    'flagged_at' => isset($crossSeller[$hmac]) ? $now : null,
                    'import_batch_id' => $batch->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                StorageItem::query()->insert($chunk);
            }

            $this->audit->log('storage.imported', $group, null, [
                'batch_id' => $batch->id,
                'source' => $source,
                'total' => $total,
                'accepted' => $batch->count_accepted,
                'duplicates' => $duplicates,
                'invalid' => $parsed['invalid'],
                'flagged' => $batch->count_flagged,
            ], $actor);

            if ($crossSeller !== []) {
                $this->audit->log('storage.cross_seller_duplicate', $group, null, ['batch_id' => $batch->id, 'count' => count($crossSeller)], $actor);
            }

            return $batch;
        });

        $this->refresh->handle($group);
        foreach (array_keys($frozenGroups) as $groupId) {
            $other = StorageGroup::query()->find($groupId);
            if ($other !== null) {
                $this->refresh->handle($other);
            }
        }

        return $batch;
    }
}
