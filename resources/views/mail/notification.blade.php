<x-mail::message>
# {{ $title }}

{{ $body }}

@if ($url)
<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>
@endif

<small>{{ __('mail.notification.why') }}</small>
</x-mail::message>
