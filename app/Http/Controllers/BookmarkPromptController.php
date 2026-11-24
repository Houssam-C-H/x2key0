<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "If you like it, press Ctrl+D to bookmark this website · No more prompts" (spec 1.1): dismissing stores the choice
 * on the account (user_preferences) or, for guests, in a one-year cookie.
 */
final class BookmarkPromptController
{
    public const string COOKIE = 'x2k_bookmark_dismissed';

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['bookmark_prompt_dismissed' => true]);
        }

        return back()->withCookie(Cookie::make(self::COOKIE, '1', 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));
    }

    /** Should the prompt show for this request? */
    public static function show(Request $request): bool
    {
        if ($request->cookie(self::COOKIE) === '1') {
            return false;
        }
        $user = $request->user();
        if ($user instanceof User) {
            return ! (UserPreference::query()->where('user_id', $user->id)->value('bookmark_prompt_dismissed') ?? false);
        }

        return true;
    }
}
