<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageItem;
use App\Shared\Audit\AuditLogger;

/**
 * Shows one secret to its seller (spec 8.3 / CLAUDE.md: every reveal is audit-logged). Items that belong
 * to an order (reserved, assigned, revoked) are never shown again. The route sits behind the Privacy
 * Password gate and a rate limit.
 */
final class RevealStorageItem
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{type: string, value: string|null, account: array<string, string|null>|null}
     */
    public function handle(StorageItem $item, User $actor): array
    {
        if (! $item->status->revealable()) {
            throw StorageException::because('storage.error.not_revealable');
        }

        $item->loadMissing('group');
        $plaintext = $this->vault->open($item->sealed());

        $this->audit->log('storage_items.revealed', $item, null, ['storage_group_id' => $item->storage_group_id, 'status' => $item->status->value], $actor);

        if ($item->group->item_type === StorageItemType::Account) {
            /** @var array<string, string|null> $account */
            $account = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);

            return ['type' => 'account', 'value' => null, 'account' => $account];
        }

        return ['type' => $item->group->item_type->value, 'value' => $plaintext, 'account' => null];
    }
}
