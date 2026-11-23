<?php

declare(strict_types=1);

namespace App\Domains\Certification\Providers;

use App\Domains\Certification\Contracts\KycProvider;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Str;

final class FakeKycProvider implements KycProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function start(User $user): array
    {
        return ['reference' => 'fake_'.Str::ulid(), 'redirect_url' => null];
    }
}
