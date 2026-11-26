<a href="{{ url('/admin') }}" class="relative flex items-center gap-1 rounded-lg px-2 py-1 text-sm hover:bg-gray-100 dark:hover:bg-white/5"
   title="{{ $total === 0 ? 'All queues clear' : collect($queues)->map(fn ($n, $q) => str_replace('_', ' ', $q).': '.$n)->implode(' · ') }}"
   data-queue-counter>
    <x-filament::icon icon="heroicon-o-inbox-stack" class="h-5 w-5 text-gray-500" />
    <span @class(['min-w-[1.25rem] rounded-full px-1.5 text-center text-xs font-bold', 'bg-warning-500 text-white' => $total > 0, 'bg-gray-100 text-gray-500 dark:bg-white/10' => $total === 0])>{{ $total > 999 ? '999+' : $total }}</span>
</a>
