<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Participant;
use Illuminate\Support\Facades\Broadcast;

/*
| Broadcast channels. A conversation channel carries only "something changed" signals; each reader then fetches
| the thread over HTTP so redaction stays per reader (ADR-021).
*/

Broadcast::channel('conversation.{publicId}', function (User $user, string $publicId): bool {
    $conversationId = Conversation::query()->where('public_id', $publicId)->value('id');

    return $conversationId !== null
        && Participant::query()->where('conversation_id', $conversationId)->where('user_id', $user->id)->exists();
});

Broadcast::channel('user.{id}', fn (User $user, int $id): bool => $user->id === $id);
