@php
    /** @var \App\Shared\Audit\AuditLog $log */
    $before = $log->before ?? [];
    $after = $log->after ?? [];
    $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
    $show = fn ($v) => $v === null ? '∅' : (is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
@endphp
<div class="grid gap-3 text-sm">
    <div class="text-gray-500">
        {{ $log->created_at?->toDateTimeString() }} · {{ $log->ip ?? 'no IP' }} ·
        {{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : 'no record' }}
    </div>
    <table class="w-full">
        <thead><tr class="text-gray-500"><th class="text-start">Field</th><th class="text-start">Before</th><th class="text-start">After</th></tr></thead>
        <tbody>
            @forelse ($keys as $key)
                <tr class="border-t border-gray-200 align-top dark:border-white/10">
                    <td class="py-1 pe-2 font-medium">{{ $key }}</td>
                    <td class="py-1 pe-2"><pre class="whitespace-pre-wrap break-all">{{ array_key_exists($key, $before) ? $show($before[$key]) : '' }}</pre></td>
                    <td class="py-1"><pre class="whitespace-pre-wrap break-all">{{ array_key_exists($key, $after) ? $show($after[$key]) : '' }}</pre></td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-gray-500">No values recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
