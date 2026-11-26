<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\QueueHealthPage $this */
        $h = $this->health();
        $c = $h['counters'];
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ($h['sizes'] as $queue => $size)
            <x-filament::section>
                <div class="text-xs text-gray-500">Queue “{{ $queue }}” waiting</div>
                <div class="text-lg font-bold">{{ $size === null ? 'unreachable' : number_format($size) }}</div>
            </x-filament::section>
        @endforeach
        <x-filament::section>
            <div class="text-xs text-gray-500">Failed jobs (24 h / all)</div>
            <div @class(['text-lg font-bold', 'text-danger-600' => $c['failed24h'] > 0])>{{ $c['failed24h'] }} / {{ $c['failedTotal'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500">E-mails sent (24 h)</div>
            <div class="text-lg font-bold">{{ number_format($c['mails24h']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500">Push subscriptions (users)</div>
            <div class="text-lg font-bold">{{ number_format($c['pushSubscriptions']) }} ({{ number_format($c['pushUsers']) }})</div>
            <div class="text-xs text-gray-500">+{{ $c['pushNew7d'] }} in 7 days</div>
        </x-filament::section>
    </div>

    {{ $this->table }}

    <x-filament::section heading="Latest e-mails sent" description="Recipient and subject only; bodies are never stored." collapsible>
        <x-admin.rows :rows="$h['mails']" :columns="['at' => 'Sent', 'to' => 'To', 'subject' => 'Subject', 'mailable' => 'Type']" empty="No e-mails sent yet." />
    </x-filament::section>
</x-filament-panels::page>
