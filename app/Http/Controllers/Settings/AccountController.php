<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Identity\Enums\MessengerType;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserMessenger;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Account (spec 1.9): profile, preferences, messenger handles.
 * Social account binding needs provider credentials and arrives later (listed as "coming soon").
 */
final class AccountController
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['level', 'messengers', 'preferences']);

        return Inertia::render('Settings/Account', [
            'account' => [
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'publicId' => $user->public_id,
                'locale' => $user->locale,
                'displayCurrency' => $user->display_currency,
                'country' => $user->country,
                'timezone' => $user->timezone,
                'level' => $user->level?->label(),
                'memberSince' => $user->created_at->toDateString(),
                'sellerPromoEmails' => $user->preferences->seller_promo_emails ?? true,
            ],
            'messengers' => collect(MessengerType::cases())->map(fn (MessengerType $type): array => [
                'type' => $type->value,
                'handle' => $user->messengers->firstWhere('type', $type)->handle ?? '',
            ])->all(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, locale: string, display_currency: string, country: string|null, timezone: string, seller_promo_emails: bool, messengers?: array<string, string|null>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:64'],
            'locale' => ['required', Rule::in(ResolveLocale::supportedLocales())],
            'display_currency' => ['required', Rule::in(config()->array('x2key.display_currencies'))],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
            'seller_promo_emails' => ['boolean'],
            'messengers' => ['array'],
            'messengers.*' => ['nullable', 'string', 'max:128'],
        ]);

        $messengerTypes = array_map(static fn (MessengerType $t): string => $t->value, MessengerType::cases());

        DB::transaction(function () use ($user, $data, $messengerTypes): void {
            $user->forceFill([
                'name' => $data['name'],
                'locale' => $data['locale'],
                'display_currency' => $data['display_currency'],
                'country' => isset($data['country']) ? mb_strtoupper($data['country']) : null,
                'timezone' => $data['timezone'],
            ])->save();

            $user->preferences()->updateOrCreate(['user_id' => $user->id], ['seller_promo_emails' => $data['seller_promo_emails']]);

            foreach ($data['messengers'] ?? [] as $type => $handle) {
                if (! in_array($type, $messengerTypes, true)) {
                    continue;
                }

                if ($handle === null || trim($handle) === '') {
                    UserMessenger::query()->where('user_id', $user->id)->where('type', $type)->delete();
                } else {
                    UserMessenger::query()->updateOrCreate(['user_id' => $user->id, 'type' => $type], ['handle' => trim($handle)]);
                }
            }
        });

        return back()
            ->with('success', __('settings.saved'))
            ->withCookie(cookie(ResolveLocale::LOCALE_COOKIE, $data['locale'], 60 * 24 * 365))
            ->withCookie(cookie(ResolveLocale::CURRENCY_COOKIE, $data['display_currency'], 60 * 24 * 365));
    }
}
