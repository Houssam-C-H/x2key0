{{-- Staff view of a ticket (spec Part 11): form answers, full thread incl. internal notes, files, history. --}}
@php /** @var \App\Domains\Tickets\Models\Ticket $ticket */ @endphp
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-2">
        <div><span class="text-gray-500">User:</span> {{ $ticket->user->username }} ({{ $ticket->user->email }})</div>
        <div><span class="text-gray-500">Topic:</span> {{ str_replace('_', ' ', $ticket->topic->value) }} · {{ $ticket->priority->value }}</div>
        <div><span class="text-gray-500">Order:</span> {{ $ticket->order?->number ?? '—' }}</div>
        <div><span class="text-gray-500">SLA:</span> {{ $ticket->first_response_due_at?->toDayDateTimeString() ?? '—' }}{{ $ticket->isOverdue() ? ' (overdue)' : '' }}</div>
    </div>

    @if (! empty($ticket->form_data))
        <div class="rounded border p-3">
            @foreach ($ticket->form_data as $key => $value)
                <div><span class="text-gray-500">{{ str_replace('_', ' ', $key) }}:</span> {{ $value }}</div>
            @endforeach
        </div>
    @endif

    <div class="space-y-2">
        @foreach ($ticket->messages->sortBy('id') as $message)
            <div class="rounded border p-2 {{ $message->is_internal ? 'bg-yellow-50' : '' }}">
                <div class="text-xs text-gray-500">
                    {{ $message->author_role }}{{ $message->author ? ' · '.$message->author->username : '' }} · {{ $message->created_at->toDayDateTimeString() }}
                    @if ($message->is_internal) · <strong>internal</strong> @endif
                </div>
                <div class="whitespace-pre-line">{{ $message->author_role === 'system' ? __($message->body) : $message->body }}</div>
                @foreach ($message->attachments as $file)
                    <div class="text-xs text-gray-600">📎 {{ $file->original_name }} ({{ $file->mime }}, {{ number_format($file->size / 1024) }} KB, sha256 {{ substr($file->sha256, 0, 12) }}…)</div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if ($ticket->satisfaction)
        <div><span class="text-gray-500">CSAT:</span> {{ $ticket->satisfaction }}/5 {{ $ticket->satisfaction_comment }}</div>
    @endif

    <ul class="text-xs text-gray-600">
        @foreach ($ticket->history->sortBy('id') as $row)
            <li>{{ $row->created_at->toDayDateTimeString() }} · {{ $row->from_status?->value ?? '—' }} → {{ $row->to_status->value }} · {{ $row->actor_type }}{{ $row->reason ? ' · '.$row->reason : '' }}</li>
        @endforeach
    </ul>
</div>
