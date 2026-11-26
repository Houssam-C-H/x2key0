<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\AffiliateBills $this */
        $bills = $this->bills();
        $m = fn (int $v) => \App\Filament\Pages\AffiliateBills::money($v);
        $sum = fn (string $k) => array_sum(array_column($bills, $k));
    @endphp

    <label class="text-sm">Month <input type="month" wire:model.live="month" class="block rounded-lg border-gray-300 dark:bg-gray-900" /></label>

    <x-filament::section heading="Bills" :description="count($bills).' affiliates with ledger activity this month'">
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Affiliate</th><th class="text-end">Commissions</th><th class="text-end">Video rewards</th><th class="text-end">Reversals</th><th class="text-end">Net earned</th><th class="text-end">To Credits</th></tr></thead>
            <tbody>
                @forelse ($bills as $b)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1"><x-filament::link :href="\App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $b['userId']])">{{ $b['username'] }}</x-filament::link></td>
                        <td class="py-1 text-end">{{ $m($b['commission']) }}</td>
                        <td class="py-1 text-end">{{ $m($b['rewards']) }}</td>
                        <td class="py-1 text-end">{{ $m($b['reversals']) }}</td>
                        <td class="py-1 text-end font-semibold">{{ $m($b['net']) }}</td>
                        <td class="py-1 text-end">{{ $m($b['transferred']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-1 text-gray-500">No affiliate activity in this month.</td></tr>
                @endforelse
            </tbody>
            @if ($bills !== [])
                <tfoot><tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20"><td class="py-1">Total</td><td class="text-end">{{ $m($sum('commission')) }}</td><td class="text-end">{{ $m($sum('rewards')) }}</td><td class="text-end">{{ $m($sum('reversals')) }}</td><td class="text-end">{{ $m($sum('net')) }}</td><td class="text-end">{{ $m($sum('transferred')) }}</td></tr></tfoot>
            @endif
        </table>
    </x-filament::section>

    <x-filament::section heading="Transfers to Credits">
        <x-admin.rows :rows="array_map(fn ($t) => [...$t, 'amount' => $m($t['amount'])], $this->transfers())" :columns="['at' => 'When', 'user' => 'User', 'amount' => 'Amount']" empty="No transfers this month." />
    </x-filament::section>
</x-filament-panels::page>
