<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use DomainException;

/**
 * Staff revoking a seller API token (docs/ADMIN_PLAN.md item 10), e.g. after a leak. Merchant managers and admins;
 * a reason is required and kept in the audit log. Token values are hashed and never shown.
 */
final class RevokeApiToken
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function byStaff(User $staff, User $owner, int $tokenId, string $reason): void
    {
        if (! $staff->hasStaffRole(StaffRole::MerchantManager) && ! $staff->hasStaffRole(StaffRole::Admin)) {
            throw new DomainException('Only merchant managers and admins can revoke API tokens.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('Give a reason (at least 5 characters).');
        }

        $token = $owner->tokens()->whereKey($tokenId)->first();
        if ($token === null) {
            throw new DomainException('This token no longer exists.');
        }

        $this->audit->log('api_token.revoked_by_staff', $token, null, ['name' => $token->name, 'owner' => $owner->id, 'reason' => trim($reason)], $staff);
        $token->delete();
    }
}
