<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Actions\OfferCsv;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Seller Center › Active offers: CSV export and import (OfferCsv). */
final class OfferCsvController
{
    use ResolvesSeller;

    public function export(Request $request, OfferCsv $csv): StreamedResponse
    {
        $stream = $csv->export($this->seller($request));

        return response()->streamDownload(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 'x2key-offers-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request, OfferCsv $csv): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $file = $request->file('file');
        abort_unless($file !== null && ! is_array($file), 422);
        $result = $csv->import($this->seller($request), $user, $file->getRealPath());

        return back()
            ->with($result['errors'] === [] ? 'success' : 'error', __('offer.csv.result', ['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])]))
            ->with('csvErrors', array_slice($result['errors'], 0, 50));
    }
}
