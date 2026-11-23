<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Support;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Identity\Models\User;

/**
 * The announcement centre (spec 12.2): one stream of system notifications (rows in `user_inbox`) and platform
 * announcements (visible by audience, state materialised in `user_inbox` on first read/star). Item ids are
 * `n{inboxId}` for notifications and `a{announcementId}` for announcements.
 */
final class AnnouncementCenter
{
    public const array FILTERS = ['all', 'unread', 'read', 'starred'];

    private const int LIMIT = 100;

    /**
     * @return list<array{id: string, kind: string, title: string, body: string, category: string, href: string|null, read: bool, starred: bool, at: string}>
     */
    public function items(User $user, string $filter, ?string $category, string $locale): array
    {
        $items = [];

        $notifications = InboxItem::query()->where('user_id', $user->id)->whereNull('announcement_id')
            ->when($category !== null, fn ($q) => $q->where('category', $category))
            ->orderByDesc('id')->limit(self::LIMIT)->get();
        foreach ($notifications as $n) {
            $data = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $n->data ?? []);
            $type = (string) $n->notification_type;
            $items[] = [
                'id' => "n{$n->id}",
                'kind' => 'notification',
                'title' => (string) __("notify.{$type}.title", $data),
                'body' => (string) __("notify.{$type}.body", $data),
                'category' => $n->category,
                'href' => $data['href'] ?? null,
                'read' => $n->read_at !== null,
                'starred' => $n->starred_at !== null,
                'at' => $n->created_at->toIso8601String(),
            ];
        }

        $announcements = Announcement::query()->visibleTo($user)
            ->when($category !== null, fn ($q) => $q->where('category', $category))
            ->orderByDesc('publish_at')->limit(self::LIMIT)->get();
        $state = InboxItem::query()->where('user_id', $user->id)->whereIn('announcement_id', $announcements->pluck('id'))->get()->keyBy('announcement_id');
        foreach ($announcements as $a) {
            $row = $state->get($a->id);
            $items[] = [
                'id' => "a{$a->id}",
                'kind' => 'announcement',
                'title' => $a->translate('title', $locale),
                'body' => $a->translate('body', $locale),
                'category' => $a->category,
                'href' => null,
                'read' => $row?->read_at !== null,
                'starred' => $row?->starred_at !== null,
                'at' => $a->publish_at->toIso8601String(),
            ];
        }

        $items = array_values(array_filter($items, static fn (array $i): bool => match ($filter) {
            'unread' => ! $i['read'],
            'read' => $i['read'],
            'starred' => $i['starred'],
            default => true,
        }));
        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, self::LIMIT);
    }

    /** Header bell badge (spec 12.2): unread notifications plus unread visible announcements. */
    public function unreadCount(User $user): int
    {
        $system = InboxItem::query()->where('user_id', $user->id)->whereNull('announcement_id')->whereNull('read_at')->count();

        $visible = Announcement::query()->visibleTo($user)->pluck('id');
        $read = $visible->isEmpty() ? 0 : InboxItem::query()->where('user_id', $user->id)->whereIn('announcement_id', $visible)->whereNotNull('read_at')->count();

        return $system + max(0, $visible->count() - $read);
    }

    public function setRead(User $user, string $id, bool $read): bool
    {
        return $this->update($user, $id, fn (InboxItem $row) => $row->forceFill(['read_at' => $read ? ($row->read_at ?? now()) : null]));
    }

    public function toggleStar(User $user, string $id): bool
    {
        return $this->update($user, $id, fn (InboxItem $row) => $row->forceFill(['starred_at' => $row->starred_at === null ? now() : null]));
    }

    public function readAll(User $user): void
    {
        InboxItem::query()->where('user_id', $user->id)->whereNull('announcement_id')->whereNull('read_at')->update(['read_at' => now()]);

        foreach (Announcement::query()->visibleTo($user)->get(['id']) as $announcement) {
            $row = self::stateRow($user, $announcement->id);
            if ($row->read_at === null) {
                $row->forceFill(['read_at' => now()])->save();
            }
        }
    }

    /**
     * @param  callable(InboxItem): InboxItem  $change
     */
    private function update(User $user, string $id, callable $change): bool
    {
        if (preg_match('/^([na])(\d+)$/', $id, $m) !== 1) {
            return false;
        }

        if ($m[1] === 'n') {
            $row = InboxItem::query()->where('user_id', $user->id)->whereNull('announcement_id')->find((int) $m[2]);
            if ($row === null) {
                return false;
            }
            $change($row)->save();

            return true;
        }

        if (! Announcement::query()->visibleTo($user)->whereKey((int) $m[2])->exists()) {
            return false;
        }

        $change(self::stateRow($user, (int) $m[2]))->save();

        return true;
    }

    /** The user's read/star state of an announcement; created (unsaved) on first interaction. */
    private static function stateRow(User $user, int $announcementId): InboxItem
    {
        $row = InboxItem::query()->where('user_id', $user->id)->where('announcement_id', $announcementId)->first();

        return $row ?? (new InboxItem)->forceFill([
            'user_id' => $user->id,
            'announcement_id' => $announcementId,
            'category' => 'announcement',
            'created_at' => now(),
        ]);
    }
}
