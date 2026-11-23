<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\Participant;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\DB;

/** Finds or creates a conversation (spec 12.1): one per order, one pre-sale chat per buyer and store. */
final class OpenConversation
{
    /** Order chat, created when the order is paid; idempotent. */
    public function forOrder(Order $order): Conversation
    {
        return DB::transaction(function () use ($order): Conversation {
            $existing = Conversation::query()->where('order_id', $order->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $seller = SellerProfile::query()->findOrFail($order->seller_profile_id);
            $conversation = Conversation::query()->create([
                'type' => Conversation::ORDER,
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'seller_profile_id' => $seller->id,
                'last_message_at' => now(),
            ]);
            $this->join($conversation, $order->buyer_id, $seller->user_id);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => null,
                'sender_role' => 'system',
                'kind' => 'system',
                'body' => 'chat.system.order_paid',
                'flags' => ['params' => ['number' => $order->number]],
            ]);

            return $conversation->refresh();
        });
    }

    /** "Contact seller" from a store or offer. Returns null when the store cannot be contacted. */
    public function preSale(User $buyer, SellerProfile $seller): ?Conversation
    {
        if ($seller->user_id === $buyer->id || ! $seller->status->isPublic()) {
            return null;
        }

        return DB::transaction(function () use ($buyer, $seller): Conversation {
            $existing = Conversation::query()
                ->where('type', Conversation::PRE_SALE)->where('buyer_id', $buyer->id)->where('seller_profile_id', $seller->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $conversation = Conversation::query()->create([
                'type' => Conversation::PRE_SALE,
                'order_id' => null,
                'buyer_id' => $buyer->id,
                'seller_profile_id' => $seller->id,
                'last_message_at' => null,
            ]);
            $this->join($conversation, $buyer->id, $seller->user_id);

            return $conversation->refresh();
        });
    }

    private function join(Conversation $conversation, int ...$userIds): void
    {
        foreach ($userIds as $userId) {
            Participant::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'last_read_message_id' => null,
                'muted' => false,
            ]);
        }
    }
}
