<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\Participant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chat screens (spec 12.1): the conversation list with unread counts, and a thread as one participant sees
 * it. The counterparty gets `body_redacted`; the sender sees their own text plus a "hidden" marker.
 */
final class ChatPresenter
{
    public const int THREAD_LIMIT = 200;

    public function __construct(private readonly Presence $presence) {}

    /**
     * Unread messages per conversation for a user (messages by others after the read marker).
     *
     * @return array<int, int> conversation id => unread count
     */
    public function unreadByConversation(User $user): array
    {
        /** @var array<int, int> $rows */
        $rows = DB::table('messages as m')
            ->join('conversation_participants as p', 'p.conversation_id', '=', 'm.conversation_id')
            ->where('p.user_id', $user->id)
            ->where('m.kind', '!=', 'system')
            ->where(static function (Builder $q) use ($user): void {
                $q->whereNull('m.sender_id')->orWhere('m.sender_id', '!=', $user->id);
            })
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as id, COUNT(*) as n')
            ->pluck('n', 'id')
            ->map(static fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)
            ->all();

        return $rows;
    }

    /** Header badge: conversations with something unread. */
    public function unreadConversations(User $user): int
    {
        return count($this->unreadByConversation($user));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $ids = Participant::query()->where('user_id', $user->id)->pluck('conversation_id');
        $conversations = Conversation::query()->whereIn('id', $ids)->with(['buyer:id,name,last_seen_at', 'seller:id,user_id,store_name,slug', 'seller.user:id,last_seen_at', 'order:id,number,public_id'])
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(200)->get();

        $lastIds = Message::query()->whereIn('conversation_id', $ids)->groupBy('conversation_id')->selectRaw('MAX(id) as id')->pluck('id');
        $last = Message::query()->whereIn('id', $lastIds)->get()->keyBy('conversation_id');
        $unread = $this->unreadByConversation($user);

        $out = [];
        foreach ($conversations as $c) {
            $message = $last->get($c->id);
            $out[] = [
                'id' => $c->public_id,
                'type' => $c->type,
                'with' => $this->counterparty($c, $user),
                'order' => $c->order?->number,
                'last' => $message === null ? null : ['text' => $this->preview($message, $user), 'at' => $message->created_at->toIso8601String()],
                'unread' => $unread[$c->id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function thread(Conversation $conversation, User $user): array
    {
        $messages = Message::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(self::THREAD_LIMIT)->get()->reverse()->values();

        return array_values($messages->map(fn (Message $m): array => $this->message($m, $user))->all());
    }

    /**
     * @return array{name: string, online: bool, href: string|null}
     */
    public function counterparty(Conversation $c, User $viewer): array
    {
        if ($viewer->id === $c->buyer_id) {
            $seller = $c->seller;

            return [
                'name' => $seller->store_name ?? '—',
                'online' => $this->presence->online($seller?->user->last_seen_at),
                'href' => $seller === null ? null : $seller->path(app()->getLocale()),
            ];
        }

        return ['name' => $c->buyer->name, 'online' => $this->presence->online($c->buyer->last_seen_at), 'href' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $m, User $viewer): array
    {
        $mine = $m->sender_id === $viewer->id;

        return [
            'id' => $m->id,
            'mine' => $mine,
            'role' => $m->sender_role,
            'kind' => $m->kind,
            'text' => $this->text($m, $viewer),
            'hidden' => $mine && $m->body_redacted !== null,
            'at' => $m->created_at->toIso8601String(),
        ];
    }

    private function preview(Message $m, User $viewer): string
    {
        return mb_substr($this->text($m, $viewer), 0, 80);
    }

    /** What this viewer reads: translated system text, their own original, or the redacted body for the counterparty. */
    private function text(Message $m, User $viewer): string
    {
        if ($m->kind === 'system') {
            $params = [];
            foreach (is_array($m->flags['params'] ?? null) ? $m->flags['params'] : [] as $key => $value) {
                $params[(string) $key] = is_scalar($value) ? (string) $value : '';
            }

            return (string) __($m->body, $params);
        }

        return $m->sender_id === $viewer->id ? $m->body : ($m->body_redacted ?? $m->body);
    }
}
