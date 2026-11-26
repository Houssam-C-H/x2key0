<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\Operations $this */
        $checks = $this->checks();
        $backups = $this->backups();
    @endphp

    <x-filament::section heading="Health checks" description="The same checks as /health and ops:check.">
        <div class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($checks as $name => $check)
                <div class="flex items-start gap-2">
                    <x-filament::badge :color="$check['ok'] ? 'success' : 'danger'">{{ $check['ok'] ? 'ok' : 'fail' }}</x-filament::badge>
                    <div><div class="font-medium">{{ $name }}</div><div class="text-gray-500">{{ $check['detail'] }}</div></div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Scheduled jobs" description="Last run as recorded by the scheduler on this cache.">
        <table class="w-full text-sm">
            <thead><tr class="text-gray-500"><th class="text-start">Command</th><th class="text-start">When</th><th class="text-start">Last run</th><th class="text-start">Result</th><th class="text-end">Seconds</th></tr></thead>
            <tbody>
                @foreach ($this->schedule() as $job)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 font-mono text-xs">{{ $job['command'] }}</td>
                        <td class="py-1 font-mono text-xs">{{ $job['cron'] }}</td>
                        <td class="py-1">{{ $job['lastRun'] }}</td>
                        <td class="py-1"><span @class(['text-danger-600' => str_starts_with($job['result'], 'failed') || str_starts_with($job['result'], 'exit')])>{{ $job['result'] }}</span></td>
                        <td class="py-1 text-end">{{ $job['seconds'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Backups" :description="($backups['required'] ? 'Required here. ' : 'Not required in this environment. ').'Last good backup: '.($backups['last'] ?? 'none recorded')">
            <x-admin.rows :rows="$backups['sets']" :columns="['stamp' => 'Set', 'files' => 'Files', 'size' => 'Size']" empty="No backup sets on the backup disk." />
        </x-filament::section>
        <x-filament::section heading="Display exchange rates" description="Units per 1 USD (display only; money stays in USD).">
            <x-admin.rows :rows="$this->rates()" :columns="['currency' => 'Currency', 'rate' => 'Per USD', 'source' => 'Source', 'fetched' => 'Fetched']" empty="No rates yet (fx:refresh)." />
        </x-filament::section>
    </div>
</x-filament-panels::page>
