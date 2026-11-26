<x-filament-panels::page>
    @php /** @var \App\Filament\Pages\ChatTranscript $this */ @endphp

    <x-filament::section>
        <dl class="grid gap-2 text-sm sm:grid-cols-4">
            @foreach ($this->header as $label => $value)
                <div><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-2 text-xs text-gray-500">Read-only. This opening is recorded in the audit log.</p>
    </x-filament::section>

    <x-filament::section heading="Messages">
        <ol class="grid gap-3 text-sm">
            @forelse ($this->messages as $m)
                <li @class(['rounded-lg p-3', 'bg-gray-50 dark:bg-white/5' => ! $m['flagged'], 'bg-warning-50 dark:bg-warning-500/10' => $m['flagged']])>
                    <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                        <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $m['sender'] }}</span>
                        <span>{{ $m['role'] }}</span>
                        @if ($m['kind'] !== 'text') <span>· {{ str_replace('_', ' ', $m['kind']) }}</span> @endif
                        <span>· {{ $m['at'] }}</span>
                        @if ($m['flagged']) <x-filament::badge size="sm" color="warning">contact details hidden</x-filament::badge> @endif
                    </div>
                    <div class="mt-1 whitespace-pre-line break-words">{{ $m['body'] }}</div>
                    @if ($m['shown'] !== null)
                        <div class="mt-1 text-xs text-gray-500">The other party saw: <span class="whitespace-pre-line">{{ $m['shown'] }}</span></div>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">No messages.</li>
            @endforelse
        </ol>
    </x-filament::section>
</x-filament-panels::page>
