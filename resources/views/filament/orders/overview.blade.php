<x-filament-panels::page>
    @php
        /** @var \App\Filament\Resources\Orders\Pages\ViewOrder $this */
        $o = $this->overview();
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Order" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ($o['summary'] as $label => $value)
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium break-words">{{ $value }}</dd></div>
                @endforeach
                <div>
                    <dt class="text-gray-500">Buyer</dt>
                    <dd class="font-medium"><x-filament::link :href="\App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $o['buyer']['id']])">{{ $o['buyer']['name'] }}</x-filament::link></dd>
                </div>
                <div>
                    <dt class="text-gray-500">Store</dt>
                    <dd class="font-medium"><x-filament::link :href="\App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource::getUrl('view', ['record' => $o['store']['id']])">{{ $o['store']['name'] }}</x-filament::link></dd>
                </div>
            </dl>
            @if ($this->auditUrl())
                <div class="mt-3 text-sm"><x-filament::link :href="$this->auditUrl()">Audit log for this order</x-filament::link></div>
            @endif
        </x-filament::section>

        <div class="grid gap-6">
            <x-filament::section heading="Money">
                <dl class="grid gap-1 text-sm">
                    @foreach ($o['money'] as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-end font-medium">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
            <x-filament::section heading="Risk">
                <div class="text-sm">Score <strong>{{ $o['risk']['score'] }}</strong> · decision <strong>{{ $o['risk']['decision'] }}</strong></div>
                <div class="mt-2">
                    <x-admin.rows :rows="$o['risk']['flags']" :columns="['rule' => 'Rule', 'score' => 'Score', 'status' => 'Status', 'at' => 'When']" empty="No risk flags." />
                </div>
            </x-filament::section>
        </div>
    </div>

    <x-filament::section heading="Status history">
        <x-admin.rows :rows="$o['history']" :columns="['at' => 'When', 'change' => 'Status', 'actor' => 'Actor', 'reason' => 'Reason']" />
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Payments">
            <x-admin.rows :rows="$o['payments']" :columns="['provider' => 'Provider', 'amount' => 'Amount', 'status' => 'Status', 'card' => 'Card', 'failure' => 'Failure', 'at' => 'When']" empty="No payments." />
        </x-filament::section>
        <x-filament::section heading="Refunds">
            <x-admin.rows :rows="$o['refunds']" :columns="['amount' => 'Amount', 'destination' => 'To', 'status' => 'Status', 'reason' => 'Reason', 'by' => 'By', 'at' => 'When']" empty="No refunds." />
        </x-filament::section>
        <x-filament::section heading="Deliveries" :description="'Secrets are never shown here. Buyer reveals: '.$o['reveals']">
            <x-admin.rows :rows="$o['deliveries']" :columns="['qty' => 'Units', 'method' => 'Method', 'by' => 'By', 'at' => 'When']" empty="Nothing delivered yet." />
        </x-filament::section>
        <x-filament::section heading="Chat">
            @if ($o['chat'])
                <div class="text-sm">
                    {{ $o['chat']['messages'] }} messages · {{ $o['chat']['flagged'] }} flagged · last {{ $o['chat']['last'] }}
                </div>
                @if (\App\Filament\Pages\ChatTranscript::canAccess())
                    <div class="mt-2 text-sm"><x-filament::link :href="\App\Filament\Pages\ChatTranscript::getUrl(['conversation' => $o['chat']['publicId'], 'context' => 'order', 'ref' => $o['summary']['Number']])">Read the transcript</x-filament::link></div>
                @endif
            @else
                <div class="text-sm text-gray-500">No conversation.</div>
            @endif
        </x-filament::section>
        <x-filament::section heading="Disputes">
            <x-admin.rows :rows="$o['disputes']" :columns="['reason' => 'Reason', 'requested' => 'Requested', 'status' => 'Status', 'at' => 'Opened']" empty="No disputes." />
        </x-filament::section>
        <x-filament::section heading="Chargebacks">
            <x-admin.rows :rows="$o['chargebacks']" :columns="['case' => 'Case', 'amount' => 'Amount', 'status' => 'Status', 'at' => 'Opened']" empty="No chargebacks." />
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Seller holds">
            <x-admin.rows :rows="$o['holds']" :columns="['kind' => 'Hold', 'amount' => 'Amount', 'release' => 'Release', 'state' => 'State']" empty="No holds." />
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

    <x-filament::section heading="Ledger journals" collapsible>
        <x-admin.rows :rows="$o['journals']" :columns="['at' => 'Posted', 'type' => 'Type', 'lines' => 'Lines']" empty="No journals yet." />
    </x-filament::section>
</x-filament-panels::page>
