@props(['rows' => [], 'columns' => [], 'empty' => 'Nothing yet.'])
{{-- Simple read-only table for admin 360° pages: $columns = [key => label], $rows = list of arrays. --}}
<table class="w-full text-sm">
    @if (collect($columns)->filter()->isNotEmpty())
        <thead>
            <tr class="text-gray-500">
                @foreach ($columns as $label)
                    <th class="text-start font-normal">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
    @endif
    <tbody>
        @forelse ($rows as $row)
            <tr class="border-t border-gray-200 align-top dark:border-white/10">
                @foreach (array_keys($columns) as $key)
                    @php $value = $row[$key] ?? ''; @endphp
                    <td class="py-1 pe-2 break-words">{{ is_array($value) ? implode(' · ', $value) : $value }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ max(1, count($columns)) }}" class="py-1 text-gray-500">{{ $empty }}</td></tr>
        @endforelse
    </tbody>
</table>
