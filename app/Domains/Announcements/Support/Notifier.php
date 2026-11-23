<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Support;

use App\Domains\Announcements\Models\InboxItem;

/**
 * System notifications into the user's inbox (spec 12.3). `$type` is the translation key suffix
 * (`notify.{type}.title` / `.body`), `$data` fills its :placeholders and may carry an `href`.
 * Never put secrets in `$data`. E-mail and live badges join with chat/Reverb (M10).
 */
final class Notifier
{
    /**
     * @param  array<string, string|int>  $data
     */
    public static function notify(int $userId, string $type, string $category, array $data = []): InboxItem
    {
        $item = new InboxItem;
        $item->forceFill([
            'user_id' => $userId,
            'announcement_id' => null,
            'notification_type' => $type,
            'category' => $category,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ])->save();

        return $item;
    }
}
