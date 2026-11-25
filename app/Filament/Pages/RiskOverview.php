<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Reporting\RiskOverview as RiskQueries;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Risk overview (fraud dashboard): open risk flags by rule and the latest, sellers by chargebacks and disputes
 * over 90 days, stores holding duplicate (frozen) keys. Admins, moderators and finance.
 */
final class RiskOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Risk overview';

    protected static ?string $slug = 'risk-overview';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.risk-overview';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Admin) || $user->hasStaffRole(StaffRole::Moderator) || $user->hasStaffRole(StaffRole::Finance));
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return app(RiskQueries::class)->summary();
    }

    public static function pct(?int $bps): string
    {
        return $bps === null ? '—' : number_format($bps / 100, 2).'%';
    }
}
