<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\OpenConversation;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\Participant;
use App\Domains\Messaging\Support\ChatPresenter;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Chat (spec 12.1, Part 20 `/chat`): list + thread, pre-sale and order conversations. Polls every few seconds. */
final class ChatController
{
    public function index(Request $request, ChatPresenter $chat, ?string $publicId = null): Response
    {
        $user = self::user($request);
        $conversation = $publicId === null ? null : self::conversation($user, $publicId);

        if ($conversation !== null) {
            self::markRead($conversation, $user);
        }

        $tab = in_array($t = $request->query('tab'), ['all', 'unread', 'read'], true) ? $t : 'all';

        return Inertia::render('Chat/Index', [
            'conversations' => $chat->list($user),
            'active' => $conversation === null ? null : [
                'id' => $conversation->public_id,
                'with' => $chat->counterparty($conversation, $user),
                'order' => $conversation->order_id === null ? null : $conversation->order()->first(['id', 'number', 'public_id'])?->only(['number', 'public_id']),
            ],
            'messages' => $conversation === null ? [] : $chat->thread($conversation, $user),
            'tab' => $tab,
            'maxLength' => SendMessage::MAX_LENGTH,
        ]);
    }

    /** Polling endpoint: the thread plus fresh unread counts. */
    public function poll(Request $request, ChatPresenter $chat, string $publicId): JsonResponse
    {
        $user = self::user($request);
        $conversation = self::conversation($user, $publicId);
        self::markRead($conversation, $user);

        return response()->json([
            'messages' => $chat->thread($conversation, $user),
            'online' => $chat->counterparty($conversation, $user)['online'],
        ]);
    }

    public function send(Request $request, SendMessage $send, string $publicId): RedirectResponse|JsonResponse
    {
        $user = self::user($request);
        $conversation = self::conversation($user, $publicId);
        $request->validate(['body' => ['required', 'string', 'max:'.SendMessage::MAX_LENGTH]]);

        $send->handle($conversation, $user, $request->string('body')->toString());

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** "Contact seller" from a store or offer. */
    public function startPreSale(Request $request, OpenConversation $open, string $slug): RedirectResponse
    {
        $user = self::user($request);
        $seller = SellerProfile::query()->public()->where('slug', $slug)->firstOrFail();
        $conversation = $open->preSale($user, $seller);
        abort_if($conversation === null, 403);

        return redirect("/chat/{$conversation->public_id}");
    }

    /** Chat button on an order page (either side). */
    public function forOrder(Request $request, OpenConversation $open, string $publicId): RedirectResponse
    {
        $user = self::user($request);
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();
        $sellerUserId = SellerProfile::query()->whereKey($order->seller_profile_id)->value('user_id');
        abort_unless($order->buyer_id === $user->id || $sellerUserId === $user->id, 404);
        abort_if($order->status === OrderStatus::PendingPayment, 404);

        return redirect('/chat/'.$open->forOrder($order)->public_id);
    }

    public function mute(Request $request, string $publicId): RedirectResponse
    {
        $user = self::user($request);
        $conversation = self::conversation($user, $publicId);
        $participant = Participant::query()->where('conversation_id', $conversation->id)->where('user_id', $user->id)->firstOrFail();
        $participant->forceFill(['muted' => ! $participant->muted])->save();

        return back();
    }

    private static function markRead(Conversation $conversation, User $user): void
    {
        $latest = Message::query()->where('conversation_id', $conversation->id)->max('id');
        if (is_numeric($latest)) {
            Participant::query()->where('conversation_id', $conversation->id)->where('user_id', $user->id)->update(['last_read_message_id' => (int) $latest]);
        }
    }

    /** A conversation the user takes part in; anything else is a 404 (never reveal other people's chats). */
    private static function conversation(User $user, string $publicId): Conversation
    {
        $conversation = Conversation::query()->where('public_id', $publicId)->first();
        abort_if($conversation === null || ! Participant::query()->where('conversation_id', $conversation->id)->where('user_id', $user->id)->exists(), 404);

        return $conversation;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
