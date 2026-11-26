<x-filament-panels::page>
    @php
        /** @var \App\Filament\Pages\StaffRoles $this */
        $matrix = $this->matrix();
        $members = $this->members();
        $group = null;
    @endphp

    <x-filament::section heading="Who holds each role">
        <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($members as $role => $people)
                <div>
                    <div class="font-semibold">{{ str_replace('_', ' ', $role) }} <span class="text-gray-500">({{ count($people) }})</span></div>
                    <ul class="mt-1">
                        @forelse ($people as $p)
                            <li><x-filament::link :href="\App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $p['id']])">{{ $p['username'] }}</x-filament::link></li>
                        @empty
                            <li class="text-gray-500">nobody</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Screens per role" description="Computed from the live access rules. Admins pass every rule except self-approval.">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-gray-500">
                    <th class="text-start">Screen</th>
                    @foreach ($matrix['roles'] as $role)
                        <th class="text-center">{{ str_replace('_', ' ', $role) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($matrix['rows'] as $row)
                    @if ($row['group'] !== $group)
                        @php $group = $row['group']; @endphp
                        <tr><td colspan="{{ count($matrix['roles']) + 1 }}" class="pt-3 text-xs font-semibold uppercase text-gray-500">{{ $group }}</td></tr>
                    @endif
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1">{{ $row['screen'] }}</td>
                        @foreach ($matrix['roles'] as $role)
                            <td class="py-1 text-center">
                                @if ($row['access'][$role] ?? false)
                                    <span class="text-success-600" aria-label="yes">✓</span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600" aria-label="no">·</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
