{{-- Printable invoice / withdrawal voucher (Part 16). Users save it as PDF from the browser's print dialog. --}}
@php
    /** @var \App\Domains\Invoices\Models\Invoice $invoice */
    $money = fn (int $minor) => number_format($minor / 100, 2).' '.$invoice->currency;
    $to = $invoice->billed_to;
    $address = is_array($to['address'] ?? null) ? $to['address'] : [];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Http\Middleware\ResolveLocale::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ $invoice->number }} · {{ $brand }}</title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; color: #111; max-width: 760px; margin: 40px auto; padding: 0 24px; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #666; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        td, th { padding: 8px 0; border-bottom: 1px solid #eee; text-align: start; }
        td.amount, th.amount { text-align: end; }
        .total td { font-weight: 700; border-bottom: 0; }
        .grid { display: flex; justify-content: space-between; gap: 24px; margin-top: 24px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">{{ __('invoice.print') }}</button></p>
    <h1>{{ __($invoice->type === 'service_invoice' ? 'invoice.type.service' : 'invoice.type.voucher') }}</h1>
    <p class="muted">{{ $brand }} · {{ __('invoice.number') }} {{ $invoice->number }} · {{ __('invoice.issued') }} {{ $invoice->issued_at->toDateString() }}</p>

    <div class="grid">
        <div>
            <strong>{{ __('invoice.billed_to') }}</strong><br>
            {{ $to['name'] ?? '' }}<br>
            @if (! empty($to['tax_id'])) {{ __('invoice.tax_id') }}: {{ $to['tax_id'] }}<br> @endif
            @foreach (['line1', 'line2', 'city', 'postal_code', 'country'] as $part)
                @if (! empty($address[$part])) {{ $address[$part] }}<br> @endif
            @endforeach
            {{ $to['email'] ?? '' }}
        </div>
        <div class="muted">
            @if ($invoice->period_start) {{ __('invoice.period') }}: {{ $invoice->period_start->toDateString() }} – {{ $invoice->period_end?->toDateString() }}<br> @endif
            @if (! empty($to['store'])) {{ __('invoice.store') }}: {{ $to['store'] }}<br> @endif
            @if (! empty($to['withdrawal'])) {{ __('invoice.withdrawal') }}: {{ $to['withdrawal'] }}<br> @endif
            @if (! empty($to['method'])) {{ __('invoice.method') }}: {{ $to['method'] }}<br> @endif
            @if (! empty($to['provider_ref'])) {{ __('invoice.reference') }}: {{ $to['provider_ref'] }} @endif
        </div>
    </div>

    <table>
        <thead><tr><th>{{ __('invoice.description') }}</th><th class="amount">{{ __('invoice.amount') }}</th></tr></thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ __($line['label'], ['count' => $line['count'] ?? 0]) }}</td>
                    <td class="amount">{{ $money((int) $line['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td>{{ __($invoice->type === 'service_invoice' ? 'invoice.total' : 'invoice.paid_out') }}</td><td class="amount">{{ $money($invoice->total) }}</td></tr>
        </tbody>
    </table>
</body>
</html>
