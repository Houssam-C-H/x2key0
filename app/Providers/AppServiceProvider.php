<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = one instance per request/job (safe under Octane/queues).
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        // Fail loudly in dev/test on lazy loading, silently discarded attributes and missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
