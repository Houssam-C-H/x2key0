<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Support;

use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeEvidence;
use App\Domains\Disputes\Models\DisputeMessage;
use App\Shared\Support\ContactInfo;
use App\Shared\Support\Translations;

/**
 * Dispute Center props (spec 1.9 "Resolution Center"). Parties never receive internal staff notes, internal
 * decision notes or the unredacted text of the other side.
 */
final class DisputePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Dispute $dispute, DisputeRole $viewer): array
    {
        $dispute->loadMissing('order.item');

        return [
            'id' => $dispute->public_id,
            'case' => strtoupper(substr($dispute->public_id, -8)),
            'href' => $dispute->path(),
            'order' => ['number' => $dispute->order->number, 'href' => $viewer === DisputeRole::Seller ? "/seller/orders/{$dispute->order->public_id}" : "/buy/orders/{$dispute->order->public_id}"],
            'reason' => $dispute->reason->value,
            'status' => $dispute->status->value,
            'openedBy' => $dispute->opened_by_role->value,
            'amount' => $dispute->order->item->unit_price_amount * $dispute->requested_qty,
            'currency' => $dispute->order->currency,
            'actionRequired' => $dispute->counterparty() === $viewer || ($dispute->proposal_by_role !== null && $dispute->proposal_by_role !== $viewer && $dispute->status === DisputeStatus::Negotiating),
            'responseDueAt' => $dispute->response_due_at?->toIso8601String(),
            'updatedAt' => $dispute->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Dispute $dispute, DisputeRole $viewer, string $locale): array
    {
        $dispute->loadMissing(['order.item', 'messages.author', 'evidence', 'decision', 'history']);
        $item = $dispute->order->item;

        return [
            ...self::row($dispute, $viewer),
            'viewer' => $viewer->value,
            'description' => self::text($dispute->description, $dispute->opened_by_role, $viewer),
            'requested' => ['resolution' => $dispute->requested_resolution->value, 'qty' => $dispute->requested_qty, 'toCredits' => $dispute->refund_to_credits],
            'proposal' => $dispute->proposal_resolution === null ? null : [
                'resolution' => $dispute->proposal_resolution->value,
                'qty' => $dispute->proposal_qty,
                'by' => $dispute->proposal_by_role?->value,
            ],
            'item' => [
                'title' => is_string($t = $item->snapshot['title'] ?? null) ? $t : '',
                'quantity' => $item->quantity,
                'delivered' => $item->delivered_qty,
                'refunded' => $item->refunded_qty,
                'unitPrice' => $item->unit_price_amount,
            ],
            'orderStatus' => $dispute->order->status->value,
            'messages' => $dispute->messages
                ->filter(static fn (DisputeMessage $m): bool => ! $m->is_internal)
                ->sortBy('id')
                ->map(static fn (DisputeMessage $m): array => self::message($m, $viewer, $locale))
                ->values()->all(),
            'evidence' => $dispute->evidence->sortBy('id')->map(static fn (DisputeEvidence $e): array => [
                'id' => $e->id,
                'name' => $e->original_name,
                'mime' => $e->mime,
                'size' => $e->size,
                'by' => $e->uploaded_by_role->value,
                'href' => "{$dispute->path()}/evidence/{$e->id}",
                'at' => $e->created_at->toIso8601String(),
            ])->values()->all(),
            'decision' => $dispute->decision === null ? null : [
                'outcome' => $dispute->decision->outcome->value,
                'byStaff' => $dispute->decision->decided_by !== null,
                'refundQty' => $dispute->decision->refund_qty,
                'refundAmount' => $dispute->decision->refund_amount,
                'refundDestination' => $dispute->decision->refund_destination,
                'redeliverQty' => $dispute->decision->redeliver_qty,
                'rationale' => $dispute->decision->rationale,
                'at' => $dispute->decision->created_at->toIso8601String(),
            ],
            'escalatedAt' => $dispute->escalated_at?->toIso8601String(),
            'closedAt' => ($dispute->resolved_at ?? $dispute->cancelled_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(DisputeMessage $m, DisputeRole $viewer, string $locale): array
    {
        if ($m->author_role === DisputeRole::System) {
            $key = is_string($m->meta['key'] ?? null) ? $m->meta['key'] : $m->body;
            /** @var array<string, string|int> $params */
            $params = is_array($m->meta['params'] ?? null) ? $m->meta['params'] : [];
            foreach (['by', 'outcome'] as $translated) {
                if (isset($params[$translated]) && is_string($params[$translated])) {
                    $params[$translated] = self::trans("dispute.{$translated}.{$params[$translated]}", $locale);
                }
            }

            return ['id' => $m->id, 'role' => 'system', 'body' => self::trans($key, $locale, $params), 'mine' => false, 'hidden' => false, 'author' => null, 'at' => $m->created_at->toIso8601String()];
        }

        $mine = $m->author_role === $viewer;

        return [
            'id' => $m->id,
            'role' => $m->author_role->value,
            'body' => $mine ? $m->body : ($m->body_redacted ?? $m->body),
            'mine' => $mine,
            'hidden' => $m->body_redacted !== null,
            'author' => $m->author_role === DisputeRole::Staff ? null : $m->author?->username,
            'at' => $m->created_at->toIso8601String(),
        ];
    }

    private static function text(string $body, DisputeRole $author, DisputeRole $viewer): string
    {
        return $author === $viewer ? $body : ContactInfo::redact($body);
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private static function trans(string $key, string $locale, array $params = []): string
    {
        $text = __($key, $params, $locale);

        return is_string($text) ? $text : Translations::pick($key);
    }
}
