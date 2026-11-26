<x-filament-panels::page>
    @php
        /** @var \App\Filament\Resources\Users\Pages\ViewUser $this */
        $o = $this->overview();
        $orderUrl = fn (int $id) => \App\Filament\Resources\Orders\OrderResource::hasPage('view') ? \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $id]) : null;
        $statusColor = match ($o['status']) { 'active' => 'success', 'suspended', 'deleting' => 'warning', default => 'danger' };
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Account" class="lg:col-span-2">
            <x-slot name="headerEnd">
                <x-filament::badge :color="$statusColor">{{ ucfirst($o['status']) }}</x-filament::badge>
            </x-slot>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ($o['profile'] as $label => $value)
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium break-all">{{ $value }}</dd></div>
                @endforeach
                @if ($o['store'])
                    <div>
                        <dt class="text-gray-500">Store</dt>
                        <dd class="font-medium">
                            <x-filament::link :href="\App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource::getUrl('edit', ['record' => $o['store']['id']])">{{ $o['store']['name'] }}</x-filament::link>
                            ({{ $o['store']['status'] }})
                        </dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        <div class="grid gap-6">
            <x-filament::section heading="Balances">
                <dl class="grid gap-2 text-sm">
                    @foreach ($o['balances'] as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $label }}</dt><dd class="font-bold">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
            <x-filament::section heading="At a glance">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-gray-500">Orders</dt><dd class="font-bold">{{ $o['counts']['orders'] }}</dd></div>
                    <div><dt class="text-gray-500">Tickets</dt><dd class="font-bold">{{ $o['counts']['tickets'] }}</dd></div>
                    <div><dt class="text-gray-500">Disputes</dt><dd class="font-bold">{{ $o['counts']['disputes'] }}</dd></div>
                    <div><dt class="text-gray-500">Violations</dt><dd class="font-bold">{{ $o['counts']['violations'] }}</dd></div>
                    <div class="col-span-2"><dt class="text-gray-500">Failed sign-ins (30 days)</dt><dd class="font-bold">{{ $o['counts']['failedLogins30d'] }}</dd></div>
                </dl>
                @if ($this->auditUrl())
                    <div class="mt-3 text-sm"><x-filament::link :href="$this->auditUrl()">Audit log for this account</x-filament::link></div>
                @endif
            </x-filament::section>
        </div>
    </div>

    <x-filament::section heading="Staff notes" :description="count($o['notes']) === 0 ? 'No notes yet. Use “Add note” above.' : null">
        <ul class="grid gap-3 text-sm">
            @foreach ($o['notes'] as $note)
                <li class="border-s-2 border-primary-500 ps-3">
                    <div class="text-gray-500">{{ $note['author'] }} · {{ $note['at'] }}</div>
                    <div class="whitespace-pre-line">{{ $note['body'] }}</div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Sessions">
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">IP</th><th class="text-start">Browser</th><th class="text-start">Last active</th><th></th></tr></thead>
                <tbody>
                    @forelse ($o['sessions'] as $s)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $s['ip'] }}</td>
                            <td class="py-1">{{ $s['agent'] }}</td>
                            <td class="py-1">{{ $s['lastActive'] }}</td>
                            <td class="py-1 text-end">
                                @if ($s['revoked'])
                                    <span class="text-gray-500">signed out</span>
                                @else
                                    <x-filament::link tag="button" color="danger" wire:click="revokeSession({{ $s['id'] }})" wire:confirm="Sign out this session?">Sign out</x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-gray-500">No sessions.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Devices">
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">Device</th><th class="text-start">First seen</th><th class="text-start">Last seen</th></tr></thead>
                <tbody>
                    @forelse ($o['devices'] as $d)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $d['name'] }} @if ($d['trusted']) <x-filament::badge size="sm" color="success">trusted</x-filament::badge> @endif</td>
                            <td class="py-1">{{ $d['firstSeen'] }}</td>
                            <td class="py-1">{{ $d['lastSeen'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-gray-500">No devices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section heading="Sign-in history" collapsible>
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">When</th><th class="text-start">Result</th><th class="text-start">IP</th><th class="text-start">Browser</th></tr></thead>
            <tbody>
                @forelse ($o['logins'] as $l)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1">{{ $l['at'] }}</td>
                        <td class="py-1"><x-filament::badge size="sm" :color="$l['ok'] ? 'success' : 'danger'">{{ $l['ok'] ? 'success' : 'failed' }}</x-filament::badge></td>
                        <td class="py-1">{{ $l['ip'] }}</td>
                        <td class="py-1">{{ $l['agent'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-gray-500">No sign-ins recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Orders (as buyer)">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($o['orders'] as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">
                                @if ($orderUrl($row['id'])) <x-filament::link :href="$orderUrl($row['id'])">{{ $row['number'] }}</x-filament::link> @else {{ $row['number'] }} @endif
                            </td>
                            <td class="py-1">{{ $row['status'] }}</td>
                            <td class="py-1 text-end">{{ $row['total'] }}</td>
                            <td class="py-1 text-end text-gray-500">{{ $row['at'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-gray-500">No orders.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Tickets">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($o['tickets'] as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $row['subject'] }}</td>
                            <td class="py-1">{{ $row['topic'] }}</td>
                            <td class="py-1">{{ $row['status'] }}</td>
                            <td class="py-1 text-end text-gray-500">{{ $row['at'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-gray-500">No tickets.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Disputes">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($o['disputes'] as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $row['order'] }}</td>
                            <td class="py-1">{{ $row['reason'] }}</td>
                            <td class="py-1">{{ $row['status'] }}</td>
                            <td class="py-1 text-end text-gray-500">{{ $row['at'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-gray-500">No disputes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Violations (store)">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($o['violations'] as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $row['rule'] }}</td>
                            <td class="py-1">{{ $row['points'] }} pts</td>
                            <td class="py-1">{{ $row['status'] }}</td>
                            <td class="py-1 text-end text-gray-500">{{ $row['at'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-gray-500">No violations.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
