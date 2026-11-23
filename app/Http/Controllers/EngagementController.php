<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Engagement\Actions\ToggleFavorite;
use App\Domains\Engagement\Models\BrowsingHistory;
use App\Domains\Engagement\Models\Favorite;
use App\Domains\Engagement\Support\EngagementReader;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Favorites and Browsing History (spec 1.7, 5.17, Part 20: favorites toggle, history removal).
 * Guests keep their history under a random cookie token.
 */
final class EngagementController
{
    public const string GUEST_COOKIE = 'x2k_history';

    public function favorites(Request $request, EngagementReader $reader): Response
    {
        return Inertia::render('Buy/Favorites', [
            'items' => $reader->favorites(self::user($request)->id, app()->getLocale()),
        ]);
    }

    public function toggleFavorite(Request $request, ToggleFavorite $toggle): RedirectResponse
    {
        $request->validate([
            'type' => ['required', Rule::in([Favorite::OFFER, Favorite::PRODUCT])],
            'id' => ['required', 'string', 'size:26'],
        ]);

        $added = $toggle->handle(self::user($request), $request->string('type')->toString(), $request->string('id')->toString());

        return back()->with('success', __($added ? 'favorites.added' : 'favorites.removed'));
    }

    public function forget(Request $request, int $id): RedirectResponse
    {
        self::ownerQuery($request)->whereKey($id)->delete();

        return back();
    }

    public function clear(Request $request): RedirectResponse
    {
        self::ownerQuery($request)->delete();

        return back()->with('success', __('history.cleared'));
    }

    /** The guest history token, created on first use (1 year). */
    public static function guestToken(Request $request, bool $create): ?string
    {
        $token = $request->cookie(self::GUEST_COOKIE);

        if (is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1) {
            return $token;
        }

        if (! $create) {
            return null;
        }

        $token = Str::random(40);
        Cookie::queue(self::GUEST_COOKIE, $token, 60 * 24 * 365);

        return $token;
    }

    /**
     * @return Builder<BrowsingHistory>
     */
    private static function ownerQuery(Request $request): Builder
    {
        $user = $request->user();

        if ($user instanceof User) {
            return BrowsingHistory::query()->where('user_id', $user->id);
        }

        $token = self::guestToken($request, false);

        return BrowsingHistory::query()->whereNull('user_id')->where('guest_token', $token ?? '-');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
