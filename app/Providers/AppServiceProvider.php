<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Catalog\Listeners\SyncProductSearch;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Platform;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Region;
use App\Domains\Catalog\Policies\CatalogPolicy;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\DatabaseCatalogSearch;
use App\Domains\Catalog\Search\MeilisearchCatalogSearch;
use App\Domains\Catalog\Support\CatalogCache;
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
use App\Domains\Sellers\Models\MerchantContact;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Sellers\Policies\SellerLevelPolicy;
use App\Domains\Sellers\Policies\SellerPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;

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

        // Listing search (ADR-011): exact database driver by default, Meilisearch in production.
        $this->app->singleton(CatalogSearch::class, static function (): CatalogSearch {
            if (config('x2key.search.driver') !== 'meilisearch') {
                return new DatabaseCatalogSearch;
            }

            $prefix = config()->string('scout.prefix', '');
            $key = config('scout.meilisearch.key');

            return new MeilisearchCatalogSearch(
                new MeilisearchClient(config()->string('scout.meilisearch.host'), is_string($key) && $key !== '' ? $key : null),
                $prefix.config()->string('x2key.search.meilisearch_index'),
            );
        });
    }

    public function boot(): void
    {
        // Fail loudly in dev/test on lazy loading, silently discarded attributes and missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Failed::class, RecordFailedLogin::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Certification::class, CertificationPolicy::class);
        Gate::policy(SellerLevel::class, SellerLevelPolicy::class);

        foreach ([SellerProfile::class, SellerServiceApplication::class, MerchantContact::class] as $sellerModel) {
            Gate::policy($sellerModel, SellerPolicy::class);
        }

        foreach ([CategoryType::class, Game::class, GameCategory::class, Product::class, Platform::class, Region::class] as $catalogModel) {
            Gate::policy($catalogModel, CatalogPolicy::class);
            // Navigation caches (mega menu, Game Index) follow catalog edits immediately.
            $catalogModel::saved(static fn () => CatalogCache::flush());
            $catalogModel::deleted(static fn () => CatalogCache::flush());
        }

        Product::observe(SyncProductSearch::class);

        // Admins can do everything, except abilities that must hold for everyone (self-review).
        Gate::before(static function (User $user, string $ability, array $arguments): ?bool {
            if ($ability === 'review' && ($arguments[0] ?? null) instanceof Certification && $arguments[0]->user_id === $user->id) {
                return false;
            }

            // Nobody decides on their own store's service applications.
            if ($ability === 'moderate' && ($arguments[0] ?? null) instanceof SellerServiceApplication && $arguments[0]->profile->user_id === $user->id) {
                return false;
            }

            return $user->hasStaffRole(StaffRole::Admin) ? true : null;
        });
    }
}
