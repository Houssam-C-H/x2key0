<x-filament-panels::page>
    @php
        /** @var \App\Filament\Resources\Sellers\SellerProfiles\Pages\ViewSellerProfile $this */
        $o = $this->overview();
        $canRevoke = \App\Filament\Support\Staff::has(\App\Domains\Identity\Enums\StaffRole::MerchantManager);
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Store" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ($o['summary'] as $label => $value)
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium break-words">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="mt-3 flex flex-wrap gap-4 text-sm">
                <x-filament::link :href="\App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $o['ownerId']])">Owner account</x-filament::link>
                @if ($this->auditUrl())
                    <x-filament::link :href="$this->auditUrl()">Audit log for this store</x-filament::link>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Sales">
            <dl class="grid gap-1 text-sm">
                @foreach ($o['sales'] as $label => $value)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-end font-medium">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Level history">
            <x-admin.rows :rows="$o['levelHistory']" :columns="['at' => 'When', 'change' => 'Change', 'by' => 'By']" empty="No level changes recorded." />
        </x-filament::section>
        <x-filament::section heading="Status history">
            <x-admin.rows :rows="$o['statusHistory']" :columns="['at' => 'When', 'change' => 'Change', 'reason' => 'Reason']" empty="No status changes." />
        </x-filament::section>
    </div>

    <x-filament::section heading="API tokens" description="Token values are never shown. Revoking signs the integration out at once.">
        @if ($canRevoke && count($o['tokens']) > 0)
            <label class="mb-2 block text-sm">Reason for revoking (audit log)
                <input type="text" wire:model="revokeReason" maxlength="200" class="block w-full rounded-lg border-gray-300 dark:bg-gray-900" />
            </label>
        @endif
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Name</th><th class="text-start">Abilities</th><th class="text-start">Last used</th><th class="text-start">Created</th><th></th></tr></thead>
            <tbody>
                @forelse ($o['tokens'] as $t)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1">{{ $t['name'] }}</td>
                        <td class="py-1">{{ $t['abilities'] }}</td>
                        <td class="py-1">{{ $t['lastUsed'] }}</td>
                        <td class="py-1">{{ $t['created'] }}</td>
                        <td class="py-1 text-end">
                            @if ($canRevoke)
                                <x-filament::link tag="button" color="danger" wire:click="revokeToken({{ $t['id'] }})" wire:confirm="Revoke this token?">Revoke</x-filament::link>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-1 text-gray-500">No API tokens.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Buyer price alerts on this store’s products" description="How many buyers wait for a lower price; never who they are.">
            <x-admin.rows :rows="$o['priceAlerts']" :columns="['product' => 'Product', 'alerts' => 'Alerts', 'below' => 'Below store price', 'lowestTarget' => 'Lowest target', 'storePrice' => 'Store price']" empty="No price alerts on these products." />
        </x-filament::section>
        <x-filament::section heading="Staff notes">
            <ul class="grid gap-3 text-sm">
                @forelse ($o['notes'] as $note)
                    <li class="border-s-2 border-primary-500 ps-3">
                        <div class="text-gray-500">{{ $note['author'] }} · {{ $note['at'] }}</div>
                        <div class="whitespace-pre-line">{{ $note['body'] }}</div>
                    </li>
                @empty
                    <li class="text-gray-500">No notes yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
