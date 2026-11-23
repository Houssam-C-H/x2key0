<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Certification\Actions\StartIdentityVerification;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Certification Center (spec 1.9): one row per level with status and the next action.
 */
final class CertificationController
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('certifications');

        $items = array_map(function (CertificationType $type) use ($user): array {
            $certification = $user->certifications->firstWhere('type', $type);
            $blockedBy = array_values(array_filter($type->requires(), fn (CertificationType $r): bool => ! $user->isCertified($r)));

            return [
                'type' => $type->value,
                'status' => $user->certificationStatus($type)->value,
                'milestone' => $type->milestone(),
                'available' => $type->milestone() === 'M2',
                'blockedBy' => array_map(static fn (CertificationType $r): string => $r->value, $blockedBy),
                'rejectedReason' => $certification?->rejected_reason,
                'verifiedAt' => $certification?->verified_at?->toIso8601String()
                    ?? match ($type) {
                        CertificationType::Email => $user->email_verified_at?->toIso8601String(),
                        CertificationType::Phone => $user->phone_verified_at?->toIso8601String(),
                        default => null,
                    },
            ];
        }, CertificationType::cases());

        return Inertia::render('Settings/Certification', ['items' => $items]);
    }

    public function startIdentity(Request $request, StartIdentityVerification $action): RedirectResponse
    {
        /** @var array{first_name: string, last_name: string, date_of_birth: string, country: string, document_type: string} $data */
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'date_of_birth' => ['required', 'date', 'before:-18 years'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'document_type' => ['required', Rule::in(['passport', 'national_id', 'driving_licence'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $redirect = $action->handle($user, $data);

        return $redirect !== null
            ? redirect()->away($redirect)
            : back()->with('success', __('certification.identity.submitted'));
    }
}
