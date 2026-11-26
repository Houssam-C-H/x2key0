<x-filament-panels::page>
    @php /** @var \App\Filament\Pages\SearchAndLanguages $this */ @endphp

    {{ $this->table }}

    <x-filament::section heading="Translation progress" description="Strings still showing in English. Export them for a translator with: php artisan lang:missing <code> --export=file.csv">
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Language</th><th class="text-start">Kind</th><th class="text-end">Translated</th><th class="text-end">Missing</th><th class="w-1/3"></th></tr></thead>
            <tbody>
                @foreach ($this->languages() as $lang)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1">{{ $lang['name'] }} <span class="text-gray-500">({{ $lang['locale'] }})</span></td>
                        <td class="py-1">{{ $lang['kind'] }}</td>
                        <td class="py-1 text-end">{{ $lang['translated'] }} / {{ $lang['total'] }}</td>
                        <td class="py-1 text-end">{{ $lang['missing'] }}</td>
                        <td class="py-1 ps-4">
                            <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $lang['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-2 rounded-full bg-primary-500" style="width: {{ $lang['percent'] }}%"></div>
                            </div>
                            <span class="text-xs text-gray-500">{{ $lang['percent'] }} %</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
