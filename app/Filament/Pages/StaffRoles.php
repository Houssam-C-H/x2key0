<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Staff;
use App\Filament\Support\StaffAccessMatrix;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Staff roles matrix (docs/ADMIN_PLAN.md item 15): which admin screens each role opens — computed from the real
 * access rules, so the menu each role sees is exactly this column — and who holds each role. Admins only; roles are
 * given on the user's Roles screen (audited).
 */
final class StaffRoles extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Staff roles';

    protected static ?string $slug = 'staff-roles';

    protected string $view = 'filament.pages.staff-roles';

    public static function canAccess(): bool
    {
        return Staff::has();
    }

    /**
     * @return array{roles: list<string>, rows: list<array{group: string, screen: string, access: array<string, bool>}>}
     */
    public function matrix(): array
    {
        return StaffAccessMatrix::build();
    }

    /**
     * @return array<string, list<array{id: int, username: string}>>
     */
    public function members(): array
    {
        return StaffAccessMatrix::members();
    }
}
