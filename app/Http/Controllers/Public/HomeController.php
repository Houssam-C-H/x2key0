<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Content\Support\HomePresenter;
use App\Domains\Engagement\Support\EngagementReader;
use App\Domains\Identity\Models\User;
use App\Http\Controllers\EngagementController;
use App\Http\Support\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home page (spec 1.3): the modules staff enabled in `home_modules`, in their order (HomePresenter). Browsing History
 * belongs to the viewer (user or guest cookie) and is added here, never cached.
 */
final class HomeController
{
    public function __invoke(Request $request, HomePresenter $home, EngagementReader $engagement, string $locale): Response
    {
        $viewer = $request->user();
        $viewerId = $viewer instanceof User ? $viewer->id : null;
        $guest = $viewerId === null ? EngagementController::guestToken($request, false) : null;

        $modules = array_map(static function (array $m) use ($engagement, $viewerId, $guest, $locale): array {
            if ($m['code'] === 'browsing_history') {
                $m['data'] = ['items' => $viewerId === null && $guest === null ? [] : $engagement->history($viewerId, $guest, $locale, 10)];
            }

            return $m;
        }, $home->modules($locale));

        return Inertia::render('Public/Home', [
            'modules' => $modules,
            'hero' => $home->hero($locale),
            'seo' => Seo::make((string) __('home.hero_tagline'), (string) __('home.hero_subtitle'), '/', $locale),
        ]);
    }
}
