<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Sellers\Actions\UpdateSellerInformation;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Support\SellerPresenter;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Validation\NoContactInfo;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seller Information (spec 1.8): shop name, store avatar, store banner, store description,
 * and the store's operating information (sale status, level, available services).
 */
final class SellerInfoController
{
    use ResolvesSeller;

    public function show(Request $request): Response
    {
        $profile = $this->seller($request)->load('level');
        $locale = app()->getLocale();

        return Inertia::render('Seller/Info', [
            'store' => [
                'name' => $profile->store_name,
                'slug' => $profile->slug,
                'publicUrl' => $profile->path($locale),
                'bio' => SellerPresenter::perLocale($profile->bio),
                'logoUrl' => $profile->logoUrl(),
                'bannerUrl' => $profile->bannerUrl(),
                'status' => $profile->status->value,
                'level' => SellerPresenter::level($profile->level, $locale),
                'payoutsEnabled' => $profile->payouts_enabled,
                'activatedAt' => $profile->activated_at?->toIso8601String(),
            ],
            'services' => SellerPresenter::passedServices($profile, $locale),
        ]);
    }

    public function update(Request $request, UpdateSellerInformation $action): RedirectResponse
    {
        $profile = $this->seller($request);

        if ($profile->status === SellerStatus::Suspended) {
            return back()->withErrors(['store_name' => __('seller.error.suspended')]);
        }

        $request->validate([
            'store_name' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u', new NoContactInfo,
                static function (string $attribute, mixed $value, Closure $fail) use ($profile): void {
                    $taken = SellerProfile::query()
                        ->whereKeyNot($profile->id)
                        ->whereRaw('lower(store_name) = ?', [mb_strtolower(is_string($value) ? trim($value) : '')])
                        ->exists();

                    if ($taken) {
                        $fail(SellerException::trans('seller.info.name_taken'));
                    }
                },
            ],
            'bio' => ['array'],
            'bio.*' => ['nullable', 'string', 'max:2000', new NoContactInfo],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=960,min_height=100'],
        ]);

        $bio = is_array($input = $request->input('bio')) ? $input : [];
        $bio = array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, array_intersect_key($bio, SellerPresenter::perLocale(null)));
        $logo = $request->file('logo');
        $banner = $request->file('banner');

        $action->handle(
            $profile,
            ['store_name' => $request->string('store_name')->toString(), 'bio' => $request->has('bio') ? $bio : null],
            $logo instanceof UploadedFile ? $logo : null,
            $banner instanceof UploadedFile ? $banner : null,
        );

        return back()->with('success', __('seller.info.saved'));
    }
}
