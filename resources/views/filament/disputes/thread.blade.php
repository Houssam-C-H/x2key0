{{-- Staff view of a dispute (spec Part 18): full thread incl. internal notes and original text, evidence, history. --}}
@php
    /** @var \App\Domains\Disputes\Models\Dispute $dispute */
    $item = $dispute->order->item;
@endphp
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-2">
        <div><span class="text-gray-500">Reason:</span> {{ str_replace('_', ' ', $dispute->reason->value) }}</div>
        <div><span class="text-gray-500">Requested:</span> {{ str_replace('_', ' ', $dispute->requested_resolution->value) }} × {{ $dispute->requested_qty }}{{ $dispute->refund_to_credits ? ' (to credits)' : '' }}</div>
        <div><span class="text-gray-500">Order status:</span> {{ $dispute->order->status->value }}</div>
        <div><span class="text-gray-500">Units:</span> {{ $item->quantity }} ordered · {{ $item->delivered_qty }} delivered · {{ $item->refunded_qty }} refunded</div>
        @if ($dispute->proposal_resolution)
            <div class="col-span-2"><span class="text-gray-500">Open proposal:</span> {{ str_replace('_', ' ', $dispute->proposal_resolution->value) }} × {{ $dispute->proposal_qty }} by {{ $dispute->proposal_by_role?->value }}</div>
        @endif
        @if ($dispute->frozen_amount > 0)
            <div class="col-span-2"><span class="text-gray-500">Seller funds frozen:</span> {{ number_format($dispute->frozen_amount / 100, 2) }} {{ $dispute->order->currency }}</div>
        @endif
    </div>

    <div class="rounded border p-3">
        <div class="mb-1 text-xs text-gray-500">Description</div>
        <div class="whitespace-pre-line">{{ $dispute->description }}</div>
    </div>

    <div class="space-y-2">
        @foreach ($dispute->messages->sortBy('id') as $message)
            <div class="rounded border p-2 {{ $message->is_internal ? 'bg-yellow-50' : '' }}">
                <div class="text-xs text-gray-500">
                    {{ $message->author_role->value }}{{ $message->author ? ' · '.$message->author->username : '' }} · {{ $message->created_at->toDayDateTimeString() }}
                    @if ($message->is_internal) · <strong>internal</strong> @endif
                    @if ($message->body_redacted) · <strong>contact details hidden from the other party</strong> @endif
                </div>
                <div class="whitespace-pre-line">{{ $message->author_role->value === 'system' ? __($message->body, is_array($message->meta['params'] ?? null) ? $message->meta['params'] : []) : $message->body }}</div>
            </div>
        @endforeach
    </div>

    @if ($dispute->evidence->isNotEmpty())
        <div>
            <div class="mb-1 font-semibold">Evidence</div>
            <ul class="list-disc ps-5">
                @foreach ($dispute->evidence as $file)
                    <li>{{ $file->original_name }} ({{ $file->uploaded_by_role->value }}, {{ $file->mime }}, {{ number_format($file->size / 1024) }} KB, sha256 {{ substr($file->sha256, 0, 12) }}…)</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($dispute->decision)
        <div class="rounded border border-green-300 p-3">
            <div class="font-semibold">Decision: {{ str_replace('_', ' ', $dispute->decision->outcome->value) }}</div>
            <div>Refund: {{ number_format($dispute->decision->refund_amount / 100, 2) }} ({{ $dispute->decision->refund_destination ?? '—' }}) · seller fault: {{ $dispute->decision->seller_fault ? 'yes' : 'no' }}</div>
            @if ($dispute->decision->rationale) <div class="mt-1 whitespace-pre-line">{{ $dispute->decision->rationale }}</div> @endif
            @if ($dispute->decision->internal_notes) <div class="mt-1 whitespace-pre-line text-gray-500">Internal: {{ $dispute->decision->internal_notes }}</div> @endif
        </div>
    @endif

    <div>
        <div class="mb-1 font-semibold">History</div>
        <ul class="text-xs text-gray-600">
            @foreach ($dispute->history->sortBy('id') as $row)
                <li>{{ $row->created_at->toDayDateTimeString() }} · {{ $row->from_status?->value ?? '—' }} → {{ $row->to_status->value }} · {{ $row->actor_type->value }}{{ $row->reason ? ' · '.$row->reason : '' }}</li>
            @endforeach
        </ul>
    </div>
</div>
