<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\ActivateSeller;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use App\Domains\Sellers\Support\SellerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seller activation (spec 3.2 / 6.3): checklist of required certifications, seller levels, activate.
 */
final class OnboardingController
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isSeller()) {
            return redirect('/seller/store');
        }

        $user->load(['certifications', 'sellerProfile']);

        $steps = array_map(static fn (CertificationType $type): array => [
            'type' => $type->value,
            'status' => $user->certificationStatus($type)->value,
            'href' => match ($type) {
                CertificationType::Email => '/email/verify',
                CertificationType::Phone => '/settings/security#phone',
                default => '/settings/certification',
            },
        ], SellerProfileStateMachine::ACTIVATION_REQUIRES);

        return Inertia::render('Seller/Onboarding', [
            'steps' => $steps,
            'canActivate' => collect($steps)->every(static fn (array $s): bool => $s['status'] === 'verified'),
            'banned' => $user->sellerProfile?->status === SellerStatus::Banned,
            'levels' => SellerPresenter::levels(app()->getLocale()),
        ]);
    }

    public function activate(Request $request, ActivateSeller $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $action->handle($user);
        } catch (SellerException $e) {
            return back()->withErrors(['activate' => $e->getMessage()]);
        }

        return redirect('/seller/store')->with('success', __('seller.onboarding.activated'));
    }
}
