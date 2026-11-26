<x-mail::message>
# Weekly report {{ $period }}

{{ $range }}

<x-mail::table>
| Figure | Value |
|:--|--:|
@foreach ($figures as $label => $value)
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>

**Open work right now:** {{ collect($queues)->filter()->map(fn ($n, $q) => str_replace('_', ' ', $q).' '.$n)->implode(' · ') ?: 'all queues clear' }}

The full report is attached as a CSV.

<x-mail::button :url="$url">
Open Reports
</x-mail::button>
</x-mail::message>
