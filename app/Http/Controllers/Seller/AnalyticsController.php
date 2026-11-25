<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Sellers\Support\SellerAnalytics;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Seller Center › Analytics: sales, conversion, top offers, delivery speed, price vs competitors. */
final class AnalyticsController
{
    use ResolvesSeller;

    public function __invoke(Request $request): Response
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;

        return Inertia::render('Seller/Analytics', [
            'analytics' => SellerAnalytics::for($this->seller($request), $days),
        ]);
    }
}
