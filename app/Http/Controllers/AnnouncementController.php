<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Announcements\Support\AnnouncementCenter;
use App\Domains\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Announcement centre (spec 12.2, Part 20): `/announcements?filter=all|unread|read|starred`. */
final class AnnouncementController
{
    public function index(Request $request, AnnouncementCenter $center): Response
    {
        $user = self::user($request);
        $filter = in_array($f = $request->query('filter'), AnnouncementCenter::FILTERS, true) ? $f : 'all';
        $category = in_array($c = $request->query('category'), Announcement::CATEGORIES, true) ? $c : null;

        return Inertia::render('Announcements/Index', [
            'items' => $center->items($user, $filter, $category, app()->getLocale()),
            'filter' => $filter,
            'category' => $category,
            'categories' => Announcement::CATEGORIES,
            'unread' => $center->unreadCount($user),
        ]);
    }

    public function read(Request $request, AnnouncementCenter $center, string $id): RedirectResponse
    {
        abort_unless($center->setRead(self::user($request), $id, $request->boolean('read', true)), 404);

        return back();
    }

    public function star(Request $request, AnnouncementCenter $center, string $id): RedirectResponse
    {
        abort_unless($center->toggleStar(self::user($request), $id), 404);

        return back();
    }

    public function readAll(Request $request, AnnouncementCenter $center): RedirectResponse
    {
        $center->readAll(self::user($request));

        return back()->with('success', __('announcements.all_read'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
