<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Admin\Support\MyAssignments;
use App\Domains\Identity\Models\User;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * My work (docs/ADMIN_PLAN.md item 15): what is assigned to the signed-in staff member — tickets, managed stores
 * with what waits there, and approvals that need them as the second person. Every staff member.
 */
final class MyWork extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'My work';

    protected static ?string $title = 'My work';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'my-work';

    protected string $view = 'filament.pages.my-work';

    public static function canAccess(): bool
    {
        return Staff::user()?->isStaff() === true;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Staff::user();
        if (! $user instanceof User) {
            return null;
        }
        $mine = MyAssignments::for($user);
        $count = count($mine['tickets']) + count($mine['approvals']);

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return array{tickets: list<array<string, mixed>>, stores: list<array<string, mixed>>, approvals: list<array<string, mixed>>}
     */
    public function assignments(): array
    {
        return MyAssignments::for(Staff::current());
    }
}
