<?php

declare(strict_types=1);

namespace App\Domains\Certification\Contracts;

use App\Domains\Identity\Models\User;

/**
 * Identity verification provider (D11: Sumsub / Veriff / Onfido). Real drivers add webhooks;
 * the fake driver leaves the check pending for staff review in the admin panel.
 */
interface KycProvider
{
    public function name(): string;

    /**
     * Starts a verification session.
     *
     * @return array{reference: string, redirect_url: string|null}
     */
    public function start(User $user): array;
}
