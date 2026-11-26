<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Spatie\Permission\Models\Role;
use Throwable;
use UnitEnum;

/**
 * Who can open which admin screen (docs/ADMIN_PLAN.md item 15), computed from the real access rules: for each staff
 * role, a throw-away user holding only that role asks every resource and page `canAccess()` (policies, Gate::before
 * and screen checks included). Nothing is saved; the signed-in user is restored afterwards.
 */
final class StaffAccessMatrix
{
    /**
     * @return array{roles: list<string>, rows: list<array{group: string, screen: string, access: array<string, bool>}>}
     */
    public static function build(): array
    {
        $panel = Filament::getPanel('admin') ?? throw new LogicException('Admin panel missing.');
        $screens = [];
        foreach ($panel->getResources() as $resource) {
            if (is_a($resource, Resource::class, true)) {
                $screens[] = ['group' => self::label($resource::getNavigationGroup()), 'screen' => self::text($resource::getNavigationLabel()), 'check' => static fn (): bool => $resource::canAccess()];
            }
        }
        foreach ($panel->getPages() as $page) {
            if (is_a($page, Page::class, true) && $page::shouldRegisterNavigation()) {
                $screens[] = ['group' => self::label($page::getNavigationGroup()), 'screen' => self::text($page::getNavigationLabel()), 'check' => static fn (): bool => $page::canAccess()];
            }
        }
        usort($screens, static fn (array $a, array $b): int => [$a['group'], $a['screen']] <=> [$b['group'], $b['screen']]);

        $roles = StaffRole::values();
        $original = Auth::user();
        $access = [];
        try {
            foreach ($roles as $role) {
                Auth::setUser(self::fakeStaff($role));
                foreach ($screens as $i => $screen) {
                    try {
                        $access[$i][$role] = (bool) ($screen['check'])();
                    } catch (Throwable) {
                        $access[$i][$role] = false;
                    }
                }
            }
        } finally {
            $original instanceof Authenticatable ? Auth::setUser($original) : Auth::forgetUser();
        }

        $rows = [];
        foreach ($screens as $i => $screen) {
            $rows[] = ['group' => $screen['group'], 'screen' => $screen['screen'], 'access' => $access[$i] ?? []];
        }

        return ['roles' => $roles, 'rows' => $rows];
    }

    /**
     * @return array<string, list<array{id: int, username: string}>> role => members
     */
    public static function members(): array
    {
        $out = array_fill_keys(StaffRole::values(), []);
        foreach (User::staffQuery()->with('roles')->orderBy('username')->get() as $user) {
            foreach ($user->getRoleNames() as $role) {
                if (is_string($role) && array_key_exists($role, $out)) {
                    $out[$role][] = ['id' => $user->id, 'username' => $user->username];
                }
            }
        }

        return $out;
    }

    private static function fakeStaff(string $role): User
    {
        $user = new User;
        $user->forceFill(['id' => 0, 'username' => "matrix-{$role}"]);
        $user->setRelation('roles', collect([new Role(['name' => $role, 'guard_name' => 'web'])]));
        $user->setRelation('permissions', collect());

        return $user;
    }

    private static function text(mixed $label): string
    {
        return $label instanceof Htmlable ? strip_tags($label->toHtml()) : (is_string($label) ? $label : '');
    }

    private static function label(mixed $group): string
    {
        return match (true) {
            $group instanceof BackedEnum => (string) $group->value,
            $group instanceof UnitEnum => $group->name,
            is_string($group) => $group,
            default => 'Other',
        };
    }
}
