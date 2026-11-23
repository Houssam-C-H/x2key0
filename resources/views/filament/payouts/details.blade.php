{{-- Payout destination for finance (decrypted on demand; every view is audited). --}}
@php
    /** @var \App\Domains\Payouts\Models\WithdrawalRequest $withdrawal */
    $method = $withdrawal->method;
    app(\App\Shared\Audit\AuditLogger::class)->log('payout_methods.viewed', $method, null, ['withdrawal' => $withdrawal->id]);
@endphp
<div class="space-y-2 text-sm">
    <div><span class="text-gray-500">Method:</span> {{ $method->type }} ({{ $method->payout_currency }})</div>
    @foreach ($method->details_encrypted as $field => $value)
        <div><span class="text-gray-500">{{ str_replace('_', ' ', $field) }}:</span> <span class="font-mono">{{ $value }}</span></div>
    @endforeach
    <div><span class="text-gray-500">Pay out:</span> {{ number_format($withdrawal->payout_amount / 100, 2) }} {{ $withdrawal->payout_currency }}</div>
</div>
