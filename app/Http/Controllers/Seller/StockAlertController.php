<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Storage\Support\StockAlerts;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The red × of the seller stock bar: hides current alerts until they resolve and trigger again. */
final class StockAlertController
{
    use ResolvesSeller;

    public function dismiss(Request $request): RedirectResponse
    {
        StockAlerts::dismiss($this->seller($request)->id);

        return back();
    }
}
