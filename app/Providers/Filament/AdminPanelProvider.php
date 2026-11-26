<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Livewire\QueueCounter;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Http\Middleware\TrackUserSession;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * Staff admin (spec Part 18). No panel login page: staff sign in through the normal Fortify
 * login (with its 2FA challenge), and guests are redirected there by redirectGuestsTo.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('X2KEY Admin')
            ->colors([
                'primary' => Color::hex('#d91a21'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Live staff counters (docs/ADMIN_PLAN.md item 11): queue counter in the top bar + websocket bridge.
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => Blade::render('@livewire(\'queue-counter\')'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => self::liveBridge())
            ->authMiddleware([
                Authenticate::class,
                TrackUserSession::class,
                RequireStaffTwoFactor::class,
            ]);
    }

    public function boot(): void
    {
        Livewire::component('queue-counter', QueueCounter::class);
    }

    /** The admin websocket bridge script, when it is built (or the Vite dev server runs). */
    public static function liveBridge(): string
    {
        $entry = 'resources/js/admin-live.ts';
        $manifest = public_path('build/manifest.json');
        $built = is_file($manifest) && str_contains((string) file_get_contents($manifest), '"'.$entry.'"');

        return Vite::isRunningHot() || $built ? app(FoundationVite::class)($entry)->toHtml() : '';
    }
}
