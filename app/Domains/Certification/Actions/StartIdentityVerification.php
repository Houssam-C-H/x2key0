<?php

declare(strict_types=1);

namespace App\Domains\Certification\Actions;

use App\Domains\Certification\Contracts\KycProvider;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Models\User;
use Illuminate\Validation\ValidationException;

final class StartIdentityVerification
{
    public function __construct(private readonly KycProvider $provider) {}

    /**
     * @param  array{first_name: string, last_name: string, date_of_birth: string, country: string, document_type: string}  $personal
     * @return string|null redirect URL to the provider's hosted flow, if any
     */
    public function handle(User $user, array $personal): ?string
    {
        foreach (CertificationType::Identity->requires() as $required) {
            if (! $user->isCertified($required)) {
                throw ValidationException::withMessages([
                    'identity' => __('certification.requires', ['type' => __("certification.type.{$required->value}")]),
                ]);
            }
        }

        $existing = $user->certifications()->where('type', CertificationType::Identity)->first();

        if ($existing !== null && in_array($existing->status, [CertificationStatus::Pending, CertificationStatus::Verified], true)) {
            throw ValidationException::withMessages(['identity' => __('certification.already_'.$existing->status->value)]);
        }

        $session = $this->provider->start($user);

        Certification::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => CertificationType::Identity],
            [
                'status' => CertificationStatus::Pending,
                'provider' => $this->provider->name(),
                'provider_ref' => $session['reference'],
                'data_encrypted' => $personal,
                'rejected_reason' => null,
                'verified_at' => null,
                'reviewed_by' => null,
            ],
        );

        return $session['redirect_url'];
    }
}
