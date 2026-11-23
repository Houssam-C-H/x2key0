<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\UpdateStoreSettings;
use App\Domains\Sellers\Enums\LanguageLevel;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Support\SellerPresenter;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Support\Settings;
use App\Shared\Validation\NoContactInfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store Management › My Store (spec 1.8 / Part 20 /seller/store): public profile link, store status,
 * vacation mode, store notice, chat auto-reply, languages spoken. Featured offers and the store video
 * arrive with offers and file storage (M5).
 */
final class StoreSettingsController
{
    use ResolvesSeller;

    public function show(Request $request, Settings $settings): Response
    {
        $profile = $this->seller($request)->load(['level', 'media']);
        $locale = app()->getLocale();

        return Inertia::render('Seller/Store', [
            'store' => [
                'name' => $profile->store_name,
                'publicUrl' => $profile->path($locale),
                'status' => $profile->status->value,
                'statusReason' => $profile->status_reason,
                'vacationUntil' => $profile->vacation_until?->toDateString(),
                'level' => SellerPresenter::level($profile->level, $locale),
                'notice' => SellerPresenter::perLocale($profile->store_notice),
                'autoReply' => [
                    'first_message' => $profile->auto_reply['first_message'] ?? null,
                    'off_hours_message' => $profile->auto_reply['off_hours_message'] ?? null,
                    'hours_start' => $profile->auto_reply['hours_start'] ?? null,
                    'hours_end' => $profile->auto_reply['hours_end'] ?? null,
                ],
                'languages' => $profile->media->languages ?? [],
            ],
            'languageOptions' => SellerPresenter::languageOptions($locale),
            'languageLevels' => LanguageLevel::values(),
            'featuredMax' => $settings->int('featured_offers_max'),
        ]);
    }

    public function update(Request $request, UpdateStoreSettings $action): RedirectResponse
    {
        $profile = $this->seller($request);

        if ($profile->status === SellerStatus::Suspended) {
            return back()->withErrors(['store' => __('seller.error.suspended')]);
        }

        $text = ['nullable', 'string', 'max:500', new NoContactInfo];

        $request->validate([
            'store_notice' => ['array'],
            'store_notice.*' => $text,
            'auto_reply' => ['array'],
            'auto_reply.first_message' => $text,
            'auto_reply.off_hours_message' => $text,
            'auto_reply.hours_start' => ['nullable', 'date_format:H:i', 'required_with:auto_reply.hours_end'],
            'auto_reply.hours_end' => ['nullable', 'date_format:H:i', 'required_with:auto_reply.hours_start'],
            'languages' => ['array', 'max:8'],
            'languages.*.code' => ['required', 'distinct', Rule::in(SellerPresenter::LANGUAGES)],
            'languages.*.level' => ['required', Rule::in(LanguageLevel::values())],
            'vacation' => ['required', 'boolean'],
            'vacation_until' => ['nullable', 'date', 'after_or_equal:today', 'before:+91 days'],
        ]);

        $notice = self::arrayInput($request, 'store_notice');
        $autoReply = self::arrayInput($request, 'auto_reply');
        $languages = [];

        foreach (self::arrayInput($request, 'languages') as $language) {
            if (is_array($language) && is_string($language['code'] ?? null) && is_string($language['level'] ?? null)) {
                $languages[] = ['code' => $language['code'], 'level' => $language['level']];
            }
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $action->handle($profile, $user, [
                'store_notice' => array_map(self::nullableString(...), array_intersect_key($notice, SellerPresenter::perLocale(null))),
                'auto_reply' => [
                    'first_message' => self::nullableString($autoReply['first_message'] ?? null),
                    'off_hours_message' => self::nullableString($autoReply['off_hours_message'] ?? null),
                    'hours_start' => self::nullableString($autoReply['hours_start'] ?? null),
                    'hours_end' => self::nullableString($autoReply['hours_end'] ?? null),
                ],
                'languages' => $languages,
                'vacation' => $request->boolean('vacation'),
                'vacation_until' => self::nullableString($request->input('vacation_until')),
            ]);
        } catch (SellerException $e) {
            return back()->withErrors(['store' => $e->getMessage()]);
        }

        return back()->with('success', __('seller.store.saved'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? $value : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
