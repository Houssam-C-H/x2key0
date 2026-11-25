<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Support;

use App\Domains\Announcements\Mail\NotificationMail;
use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * System notifications into the user's inbox (spec 12.3). `$type` is the translation key suffix
 * (`notify.{type}.title` / `.body`), `$data` fills its :placeholders and may carry an `href`.
 * Never put secrets in `$data`.
 *
 * Important types are also e-mailed (branded NotificationMail, the user's language), after the surrounding
 * transaction commits. Each maps to a preference the user can switch off in Settings › Account, or to null when
 * the e-mail is always sent (money, disputes, account safety).
 */
final class Notifier
{
    /** @var array<string, string|null> notification type => e-mail preference (null = always sent) */
    public const array EMAILED = [
        'order_new' => 'order',
        'order_delivered' => 'order',
        'order_completed' => 'order',
        'order_cancelled' => 'order',
        'checkout_abandoned' => 'order',
        'dispute_opened' => null,
        'dispute_reply' => null,
        'dispute_proposal' => null,
        'dispute_escalated' => null,
        'dispute_resolved' => null,
        'chargeback_opened' => null,
        'chargeback_won' => null,
        'chargeback_lost' => null,
        'violation_new' => null,
        'violation_decided' => null,
        'withdrawal_approved' => null,
        'withdrawal_rejected' => null,
        'withdrawal_paid' => null,
        'withdrawal_failed' => null,
        'storage_disabled' => null,
        'storage_enabled' => null,
        'ticket_reply' => 'ticket',
        'ticket_closed' => 'ticket',
        'low_stock' => 'merchant',
        'price_drop' => 'deals',
        'seller_level_up' => 'merchant',
        'seller_level_down' => 'merchant',
    ];

    /** @var array<string, int> types e-mailed at most once per this many minutes per user (the inbox gets every one) */
    public const array COOLDOWN_MINUTES = ['checkout_abandoned' => 24 * 60];

    /** Preferences shown in Settings › Account (all on by default). */
    public const array PREFERENCES = ['order', 'merchant', 'ticket', 'deals'];

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

        if (array_key_exists($type, self::EMAILED) && ! self::coolingDown($userId, $type, $item->id)) {
            DB::afterCommit(static fn () => self::email($userId, $type, $data));
        }

        return $item;
    }

    /**
     * @param  array<string, string|int>  $data
     */
    private static function email(int $userId, string $type, array $data): void
    {
        $user = User::query()->find($userId);
        if ($user === null || $user->email === '' || ! $user->status->canLogIn()) {
            return;
        }

        $preference = self::EMAILED[$type];
        if ($preference !== null && ! self::wants($userId, $preference)) {
            return;
        }

        Mail::to($user)->queue(new NotificationMail($user, $type, $data));
    }

    /** True when the same type was already sent to this user inside its cooldown window. */
    private static function coolingDown(int $userId, string $type, int $exceptId): bool
    {
        $minutes = self::COOLDOWN_MINUTES[$type] ?? 0;

        return $minutes > 0 && InboxItem::query()
            ->where('user_id', $userId)
            ->where('notification_type', $type)
            ->whereKeyNot($exceptId)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    public static function wants(int $userId, string $preference): bool
    {
        $prefs = UserPreference::query()->where('user_id', $userId)->value('email_prefs');
        $prefs = is_string($prefs) ? json_decode($prefs, true) : $prefs;

        return ! is_array($prefs) || ($prefs[$preference] ?? true) !== false;
    }
}
