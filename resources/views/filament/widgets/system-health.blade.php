<x-filament-widgets::widget>
    <x-filament::section heading="System health" :description="$healthy ? 'All checks pass.' : 'Some checks need attention (docs/RUNBOOK.md section 2).'">
        <ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
            @foreach ($checks as $name => $check)
                <li style="display:flex;gap:.6rem;align-items:flex-start;padding:.6rem .75rem;border-radius:.6rem;border:1px solid rgba(17,17,17,.08);">
                    <span style="margin-top:.35rem;width:.6rem;height:.6rem;border-radius:999px;flex-shrink:0;background:{{ $check['ok'] ? '#15803d' : '#d91a21' }}"></span>
                    <span>
                        <span style="display:block;font-weight:600;text-transform:capitalize;">{{ $name }}</span>
                        <span style="display:block;font-size:.8rem;opacity:.7;">{{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
