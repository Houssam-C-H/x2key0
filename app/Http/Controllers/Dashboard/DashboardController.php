<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Shared\Navigation\DashboardMenuPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The `menu` prop is shared for every dashboard page by HandleInertiaRequests.
 */
final class DashboardController
{
    /**
     * Dashboard home (spec 1.7): no sidebar, hero with balances, then the menu as cards.
     * Balances are placeholders until the ledger exists (M7/M9).
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard/Index');
    }

    /**
     * Every sidebar page not built yet renders this placeholder with the milestone that delivers it.
     */
    public function placeholder(Request $request, DashboardMenuPresenter $menu): Response
    {
        return Inertia::render('Shared/Placeholder', [
            'page' => $menu->present($request)['active'],
        ]);
    }
}
