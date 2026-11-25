<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\RiskOverview $this */
        $r = $this->overview();
        $p = fn ($v) => \App\Filament\Pages\RiskOverview::pct($v);
        $cards = [
            ['Open risk flags', $r['totals']['openFlags'], '/admin/risk-flags'],
            ['Chargebacks (90 days)', $r['totals']['chargebacks'], '/admin/orders'],
            ['Disputes (90 days)', $r['totals']['disputes'], '/admin/disputes'],
            ['Frozen duplicate keys', $r['totals']['frozenKeys'], '/admin/storage-groups'],
            ['Blocklist entries', $r['totals']['blocklist'], '/admin/blocklist'],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
        @foreach ($cards as [$label, $value, $url])
            <a href="{{ $url }}">
                <x-filament::section>
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                    <div class="text-2xl font-bold" @if ($value > 0) style="color:#d91a21" @endif>{{ number_format($value) }}</div>
                </x-filament::section>
            </a>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section heading="Open flags by rule">
            @if ($r['flagsByRule'] === [])
                <p class="text-sm text-gray-500">No open risk flags.</p>
            @else
                <table class="w-full text-sm">
                    @foreach ($r['flagsByRule'] as $row)
                        <tr class="border-t border-gray-100"><td class="py-1.5">{{ $row['rule'] }}</td><td class="py-1.5 text-end font-semibold">{{ $row['open'] }}</td></tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Latest open flags">
            @if ($r['recentFlags'] === [])
                <p class="text-sm text-gray-500">Nothing waiting.</p>
            @else
                <table class="w-full text-sm">
                    @foreach ($r['recentFlags'] as $f)
                        <tr class="border-t border-gray-100">
                            <td class="py-1.5">{{ $f['rule'] }}</td>
                            <td class="py-1.5 text-gray-500">{{ $f['subject'] }}</td>
                            <td class="py-1.5 text-end">score {{ $f['score'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="Sellers by chargebacks and disputes (last 90 days)" description="Rates are per paid order in the window. Review stores near the payment provider chargeback limit first.">
        @if ($r['sellers'] === [])
            <p class="text-sm text-gray-500">No chargebacks or disputes in the window.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr><th class="py-2 text-start">Store</th><th class="text-end">Paid orders</th><th class="text-end">Chargebacks</th><th class="text-end">Chargeback rate</th><th class="text-end">Disputes</th><th class="text-end">Dispute rate</th><th class="text-end">Refunded</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($r['sellers'] as $s)
                            <tr class="border-t border-gray-100">
                                <td class="py-1.5 font-semibold">{{ $s['store'] }}</td>
                                <td class="text-end">{{ $s['orders'] }}</td>
                                <td class="text-end">{{ $s['chargebacks'] }}</td>
                                <td class="text-end" @if (($s['chargebackBps'] ?? 0) >= 100) style="color:#d91a21;font-weight:600" @endif>{{ $p($s['chargebackBps']) }}</td>
                                <td class="text-end">{{ $s['disputes'] }}</td>
                                <td class="text-end" @if (($s['disputeBps'] ?? 0) >= 500) style="color:#d91a21;font-weight:600" @endif>{{ $p($s['disputeBps']) }}</td>
                                <td class="text-end">{{ $s['refunded'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Stores holding duplicate keys" description="Keys uploaded by more than one seller are frozen on both sides until risk review.">
        @if ($r['duplicateKeys'] === [])
            <p class="text-sm text-gray-500">No frozen duplicates.</p>
        @else
            <table class="w-full text-sm">
                @foreach ($r['duplicateKeys'] as $d)
                    <tr class="border-t border-gray-100"><td class="py-1.5">{{ $d['store'] }}</td><td class="py-1.5 text-end font-semibold">{{ $d['items'] }} frozen</td></tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
