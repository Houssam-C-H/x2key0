<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Invoices\Models\Invoice;
use App\Domains\Invoices\Models\InvoiceProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoice menu (spec 1.7 / Part 16): service invoices, withdrawal vouchers, Invoice info. Documents open as a
 * printable page (save as PDF from the browser) for their owner and finance staff.
 */
final class InvoiceController
{
    public function service(Request $request): Response
    {
        return $this->list($request, Invoice::SERVICE);
    }

    public function vouchers(Request $request): Response
    {
        return $this->list($request, Invoice::VOUCHER);
    }

    public function info(Request $request): Response
    {
        $profile = InvoiceProfile::query()->where('user_id', self::user($request)->id)->first();

        return Inertia::render('Invoices/Info', [
            'profile' => [
                'legal_name' => $profile->legal_name ?? '',
                'tax_id' => $profile->tax_id_encrypted ?? '',
                'line1' => $profile->address['line1'] ?? '',
                'line2' => $profile->address['line2'] ?? '',
                'city' => $profile->address['city'] ?? '',
                'postal_code' => $profile->address['postal_code'] ?? '',
                'country' => $profile->address['country'] ?? '',
                'email' => $profile->email ?? self::user($request)->email,
            ],
        ]);
    }

    public function saveInfo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'line1' => ['required', 'string', 'max:150'],
            'line2' => ['nullable', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'email' => ['required', 'email', 'max:191'],
        ]);

        InvoiceProfile::query()->updateOrCreate(['user_id' => self::user($request)->id], [
            'legal_name' => $request->string('legal_name')->trim()->toString(),
            'tax_id_encrypted' => $request->filled('tax_id') ? $request->string('tax_id')->trim()->toString() : null,
            'address' => [
                'line1' => $request->string('line1')->trim()->toString(),
                'line2' => $request->filled('line2') ? $request->string('line2')->trim()->toString() : null,
                'city' => $request->string('city')->trim()->toString(),
                'postal_code' => $request->filled('postal_code') ? $request->string('postal_code')->trim()->toString() : null,
                'country' => strtoupper($request->string('country')->toString()),
            ],
            'email' => $request->string('email')->trim()->toString(),
        ]);
        unset($data);

        return back()->with('success', __('invoice.info_saved'));
    }

    public function show(Request $request, string $number): View
    {
        $user = self::user($request);
        $invoice = Invoice::query()->where('number', $number)->first();
        abort_if($invoice === null || ($invoice->user_id !== $user->id && ! $user->hasStaffRole(StaffRole::Finance) && ! $user->hasStaffRole(StaffRole::Admin)), 404);

        return view('invoices.show', ['invoice' => $invoice, 'brand' => config()->string('x2key.brand.name')]);
    }

    private function list(Request $request, string $type): Response
    {
        $invoices = Invoice::query()->where('user_id', self::user($request)->id)->where('type', $type)->latest('issued_at')->paginate(20);

        return Inertia::render('Invoices/Index', [
            'type' => $type,
            'invoices' => [
                'data' => collect($invoices->items())->map(static fn (Invoice $i): array => [
                    'number' => $i->number,
                    'href' => $i->path(),
                    'period' => $i->period_start === null ? null : $i->period_start->format('Y-m'),
                    'total' => $i->total,
                    'currency' => $i->currency,
                    'issuedAt' => $i->issued_at->toIso8601String(),
                ])->all(),
                'page' => $invoices->currentPage(),
                'lastPage' => $invoices->lastPage(),
            ],
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
