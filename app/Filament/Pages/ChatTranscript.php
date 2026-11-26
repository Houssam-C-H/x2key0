<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Admin\Support\Format;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\StaffChatAccess;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use DomainException;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Read-only chat transcript for staff (docs/ADMIN_PLAN.md item 6), opened from an order, dispute, ticket or the
 * flagged-message queue. Opening it writes `chat.transcript_opened` to the audit log (StaffChatAccess::open).
 */
final class ChatTranscript extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'chat-transcript';

    protected static ?string $title = 'Chat transcript';

    protected string $view = 'filament.pages.chat-transcript';

    #[Url]
    public string $conversation = '';

    #[Url]
    public string $context = 'order';

    #[Url]
    public string $ref = '';

    /** @var array<string, string> */
    public array $header = [];

    /** @var list<array{id: int, at: string, role: string, sender: string, kind: string, body: string, shown: string|null, flagged: bool}> */
    public array $messages = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && StaffChatAccess::allowed($user);
    }

    /** Transcript link for the conversation of an order, or null when there is none or the viewer may not read it. */
    public static function urlForOrder(?int $orderId, string $context, string $ref): ?string
    {
        if ($orderId === null || ! self::canAccess()) {
            return null;
        }
        $publicId = Conversation::query()->where('order_id', $orderId)->value('public_id');

        return is_string($publicId) ? self::getUrl(['conversation' => $publicId, 'context' => $context, 'ref' => $ref]) : null;
    }

    public function mount(): void
    {
        $conversation = Conversation::query()->with(['buyer', 'seller', 'order'])->where('public_id', $this->conversation)->first();
        abort_if($conversation === null, 404);
        $staff = Auth::user();
        abort_unless($staff instanceof User, 403);

        try {
            $rows = app(StaffChatAccess::class)->open($staff, $conversation, $this->context, $this->ref !== '' ? $this->ref : null);
        } catch (DomainException) {
            abort(403);
        }

        $senders = User::query()->whereIn('id', $rows->pluck('sender_id')->filter()->unique())->pluck('username', 'id');
        $this->header = [
            'Buyer' => $conversation->buyer->username,
            'Store' => $conversation->seller !== null ? $conversation->seller->store_name : '—',
            'Order' => $conversation->order !== null ? $conversation->order->number : 'pre-sale',
            'Opened for' => $this->context.($this->ref !== '' ? ' '.$this->ref : ''),
        ];
        $this->messages = array_values($rows->map(fn (Message $m): array => [
            'id' => $m->id,
            'at' => Format::date($m->created_at),
            'role' => $m->sender_role,
            'sender' => $m->sender_id === null ? 'system' : (is_string($senders[$m->sender_id] ?? null) ? $senders[$m->sender_id] : '#'.$m->sender_id),
            'kind' => $m->kind,
            'body' => $m->body,
            'shown' => $m->body_redacted,
            'flagged' => $m->body_redacted !== null,
        ])->all());
    }
}
