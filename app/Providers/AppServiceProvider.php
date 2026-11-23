<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Certification\Contracts\KycProvider;
use App\Domains\Certification\Models\Certification;
use App\Domains\Certification\Policies\CertificationPolicy;
use App\Domains\Certification\Providers\FakeKycProvider;
use App\Domains\Identity\Contracts\SmsSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Listeners\RecordFailedLogin;
use App\Domains\Identity\Listeners\RecordSuccessfulLogin;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Identity\Support\LogSmsSender;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = one instance per request/job (safe under Octane/queues).
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(Settings::class);

        // Provider drivers: real ones are chosen with founder decisions (D11 KYC, SMS provider).
        $this->app->bind(KycProvider::class, FakeKycProvider::class);
        $this->app->bind(SmsSender::class, LogSmsSender::class);
    }

    public function boot(): void
    {
        // Fail loudly in dev/test on lazy loading, silently discarded attributes and missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Failed::class, RecordFailedLogin::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Certification::class, CertificationPolicy::class);

        // Admins can do everything, except abilities that must hold for everyone (self-review).
        Gate::before(static function (User $user, string $ability, array $arguments): ?bool {
            if ($ability === 'review' && ($arguments[0] ?? null) instanceof Certification && $arguments[0]->user_id === $user->id) {
                return false;
            }

            return $user->hasStaffRole(StaffRole::Admin) ? true : null;
        });
    }
}
