<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\MyWork $this */
        $mine = $this->assignments();
    @endphp

    <x-filament::section heading="Approvals waiting for you" description="Requests by another staff member that need a second person.">
        <table class="w-full text-sm">
            <tbody>
                @forelse ($mine['approvals'] as $a)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 font-medium">{{ $a['kind'] }}</td>
                        <td class="py-1">{{ $a['what'] }}</td>
                        <td class="py-1 text-gray-500">{{ \Illuminate\Support\Str::limit($a['reason'], 80) }}</td>
                        <td class="py-1 text-gray-500">{{ $a['at'] }}</td>
                        <td class="py-1 text-end"><x-filament::link :href="url($a['url'])">Open</x-filament::link></td>
                    </tr>
                @empty
                    <tr><td class="py-1 text-gray-500">Nothing waits for your approval.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Tickets assigned to you">
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Subject</th><th class="text-start">From</th><th class="text-start">Priority</th><th class="text-start">Status</th><th class="text-start">First reply due</th></tr></thead>
            <tbody>
                @forelse ($mine['tickets'] as $t)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1">{{ $t['subject'] }}</td>
                        <td class="py-1">{{ $t['user'] }}</td>
                        <td class="py-1">{{ $t['priority'] }}</td>
                        <td class="py-1">{{ $t['status'] }}</td>
                        <td class="py-1"><span @class(['font-semibold text-danger-600' => $t['overdue']])>{{ $t['due'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-1 text-gray-500">No open tickets assigned to you.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if (count($mine['tickets']) > 0)
            <div class="mt-2 text-sm"><x-filament::link :href="url('/admin/tickets?filters[mine][isActive]=1')">Open in the ticket desk</x-filament::link></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Stores you manage">
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Store</th><th class="text-start">Status</th><th class="text-end">Offers to review</th><th class="text-end">Service applications</th></tr></thead>
            <tbody>
                @forelse ($mine['stores'] as $s)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1"><x-filament::link :href="\App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource::getUrl('view', ['record' => $s['id']])">{{ $s['store'] }}</x-filament::link></td>
                        <td class="py-1">{{ $s['status'] }}</td>
                        <td class="py-1 text-end">{{ $s['offersToReview'] }}</td>
                        <td class="py-1 text-end">{{ $s['applications'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-1 text-gray-500">No stores assigned to you.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
