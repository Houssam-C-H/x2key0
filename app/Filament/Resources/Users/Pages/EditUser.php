<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Identity\Models\User;
use App\Filament\Resources\Users\UserResource;
use App\Shared\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use LogicException;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    private array $rolesBefore = [];

    protected function beforeSave(): void
    {
        $this->rolesBefore = self::names($this->userRecord());
    }

    /**
     * Role pivot changes are invisible to the Auditable trait, so log them explicitly.
     */
    protected function afterSave(): void
    {
        $user = $this->userRecord();
        $user->unsetRelation('roles');
        $rolesAfter = self::names($user);

        if ($rolesAfter !== $this->rolesBefore) {
            app(AuditLogger::class)->log('users.roles_changed', $user, ['roles' => $this->rolesBefore], ['roles' => $rolesAfter]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private static function names(User $user): array
    {
        return array_values(array_filter($user->getRoleNames()->all(), is_string(...)));
    }

    private function userRecord(): User
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            throw new LogicException('EditUser expects a User record.');
        }

        return $record;
    }
}
