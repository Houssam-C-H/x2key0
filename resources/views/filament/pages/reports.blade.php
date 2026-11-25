<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\Reports $this */
        $r = $this->report();
        $m = fn ($v) => \App\Filament\Pages\Reports::money($v);
        $p = fn ($v) => \App\Filament\Pages\Reports::pct($v);
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">From <input type="date" wire:model.live="from" class="block rounded-lg border-gray-300 dark:bg-gray-900" /></label>
        <label class="text-sm">To <input type="date" wire:model.live="to" class="block rounded-lg border-gray-300 dark:bg-gray-900" /></label>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ([
            'GMV (paid orders)' => $m($r['gmv']),
            'Orders paid / completed' => $r['orders'].' / '.$r['completed'],
            'Platform revenue' => $m($r['revenue']),
            'Take rate' => $p($r['takeRateBps']),
            'Refunds' => $m($r['refunds']).' ('.$p($r['refundRateBps']).')',
            'Chargebacks' => $r['chargebacks'].' · '.$m($r['chargebackAmount']).' ('.$p($r['chargebackRatioBps']).')',
            'Dispute rate' => $p($r['disputeRateBps']),
            'Coupon cost / affiliate cost' => $m($r['couponCost']).' / '.$m($r['affiliateCost']),
        ] as $label => $value)
            <x-filament::section>
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-lg font-bold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Work queues">
        <div class="grid grid-cols-2 gap-2 text-sm md:grid-cols-5">
            @foreach ($r['queues'] as $queue => $count)
                <div><span class="text-gray-500">{{ str_replace('_', ' ', $queue) }}:</span> <strong>{{ $count }}</strong></div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Margin per category type">
        <table class="w-full text-sm">
            <thead><tr class="text-start text-gray-500"><th class="text-start">Type</th><th class="text-end">Orders</th><th class="text-end">GMV</th><th class="text-end">Revenue</th><th class="text-end">Margin</th></tr></thead>
            <tbody>
                @forelse ($r['byType'] as $row)
                    <tr><td>{{ str_replace('_', ' ', $row['type']) }}</td><td class="text-end">{{ $row['orders'] }}</td><td class="text-end">{{ $m($row['gmv']) }}</td><td class="text-end">{{ $m($row['revenue']) }}</td><td class="text-end">{{ $p($row['marginBps']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500">No paid orders in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Top games">
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($r['topGames'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['orders'] }}</td><td class="text-end">{{ $m($row['gmv']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section heading="Top sellers (disputes in period)">
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($r['topSellers'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['orders'] }}</td><td class="text-end">{{ $m($row['gmv']) }}</td><td class="text-end">{{ $row['disputes'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
