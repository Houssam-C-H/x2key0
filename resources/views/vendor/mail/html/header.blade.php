@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim((string) config('app.url'), '/') }}/brand/x2key-logo-email.png" width="180" height="38" class="logo" alt="{{ config('app.name') }}" style="height: 38px; width: auto; max-width: 180px;">
</a>
</td>
</tr>
