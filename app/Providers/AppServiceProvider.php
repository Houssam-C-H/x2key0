<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Admin\Models\SettingChange;
use App\Domains\Affiliate\Listeners\AffiliateListeners;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateVideoRecord;
use App\Domains\Affiliate\Models\CreativeAsset;
use App\Domains\Announcements\Listeners\SendOrderNotifications;
use App\Domains\Announcements\Models\Announcement;
use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Announcements\Policies\AnnouncementPolicy;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignParticipation;
use App\Domains\Campaigns\Policies\MarketingPolicy;
use App\Domains\Campaigns\Support\CampaignBadges;
use App\Domains\Catalog\Listeners\SyncProductSearch;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Platform;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Region;
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Catalog\Policies\CatalogPolicy;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\DatabaseCatalogSearch;
use App\Domains\Catalog\Search\MeilisearchCatalogSearch;
use App\Domains\Catalog\Support\CatalogCache;
use App\Domains\Certification\Contracts\KycProvider;
use App\Domains\Certification\Models\Certification;
use App\Domains\Certification\Policies\CertificationPolicy;
use App\Domains\Certification\Providers\FakeKycProvider;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HomeModule;
use App\Domains\Content\Models\HomeModuleItem;
use App\Domains\Content\Models\NewsArticle;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\SeoLinkRow;
use App\Domains\Content\Policies\ContentPolicy;
use App\Domains\Content\Support\HomeCache;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Policies\DisputePolicy;
use App\Domains\Identity\Contracts\SmsSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Listeners\RecordFailedLogin;
use App\Domains\Identity\Listeners\RecordSuccessfulLogin;
use App\Domains\Identity\Models\AccountDeletionRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Policies\StaffReadOnlyPolicy;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Identity\Support\LogSmsSender;
use App\Domains\Invoices\Models\Invoice;
use App\Domains\Ledger\Events\HoldReleased;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Models\LedgerAdjustment;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Policies\LedgerPolicy;
use App\Domains\Ledger\Support\LedgerCreditsWallet;
use App\Domains\Messaging\Events\ConversationUpdated;
use App\Domains\Messaging\Listeners\OpenOrderConversation;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\Participant;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Policies\OfferPolicy;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderCompleted;
use App\Domains\Orders\Events\OrderDelivered;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Policies\OrderPolicy;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Models\Chargeback;
use App\Domains\Payments\Policies\ChargebackPolicy;
use App\Domains\Payments\Providers\FakePaymentProvider;
use App\Domains\Payments\Providers\StripePaymentProvider;
use App\Domains\Payouts\Models\PayoutMethodType;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\Policies\PayoutPolicy;
use App\Domains\Points\Models\PointsAdjustment;
use App\Domains\Reviews\Listeners\ReviewListeners;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Reviews\Policies\OrderCommentPolicy;
use App\Domains\Risk\Models\BlocklistEntry;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Sellers\Models\MerchantContact;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Sellers\Policies\SellerLevelPolicy;
use App\Domains\Sellers\Policies\SellerPolicy;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Vault\EnvelopeSecretVault;
use App\Domains\Tickets\Models\CannedReply;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\Policies\TicketPolicy;
use App\Domains\Violations\Listeners\RecordCancellationViolation;
use App\Domains\Violations\Models\Violation;
use App\Domains\Violations\Models\ViolationRule;
use App\Domains\Violations\Policies\ViolationPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Realtime\Signals;
use App\Shared\Support\Settings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;
use RuntimeException;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = one instance per request/job (safe under Octane/queues).
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(Settings::class);
        $this->app->scoped(CampaignBadges::class);

        // Provider drivers: real ones are chosen with founder decisions (D11 KYC, SMS provider).
        $this->app->bind(KycProvider::class, FakeKycProvider::class);

        // Credits Balance as a payment source, backed by the ledger (ADR-016).
        $this->app->bind(CreditsWallet::class, LedgerCreditsWallet::class);

        // Fake PSP (D2 until M6b): refused in production; webhook secret from config or derived from APP_KEY.
        $this->app->bind(FakePaymentProvider::class, function (): FakePaymentProvider {
            $secret = config('x2key.payments.fake.webhook_secret');

            return new FakePaymentProvider(
                cache()->store(),
                is_string($secret) && $secret !== '' ? $secret : hash_hmac('sha256', 'x2key-fake-psp-webhook', config()->string('app.key')),
                $this->app->isProduction(),
            );
        });
        $this->app->bind(StripePaymentProvider::class, static function (): StripePaymentProvider {
            $secret = config('x2key.payments.stripe.secret');
            $webhook = config('x2key.payments.stripe.webhook_secret');

            return new StripePaymentProvider(is_string($secret) ? $secret : '', is_string($webhook) ? $webhook : '');
        });
        $this->app->bind(SmsSender::class, LogSmsSender::class);

        // Storage vault (ADR-014): production must provide its own keys; dev derives them from APP_KEY.
        $this->app->singleton(SecretVault::class, function (): SecretVault {
            $master = config('x2key.storage.master_key');
            $pepper = config('x2key.storage.hmac_pepper');

            if ($this->app->isProduction() && (! is_string($master) || $master === '' || ! is_string($pepper) || $pepper === '')) {
                throw new RuntimeException('STORAGE_MASTER_KEY and STORAGE_HMAC_PEPPER must be set in production.');
            }

            return EnvelopeSecretVault::fromConfig(
                is_string($master) ? $master : null,
                is_string($pepper) ? $pepper : null,
                config()->string('app.key'),
                config()->string('x2key.storage.key_id', 'v1'),
            );
        });

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
        // Personal data export (spec Part 23): its own counter, 3 per hour per user (numeric `throttle:x,y` limits
        // share one counter per user across routes).
        // Seller API (ADR-038): 120 requests a minute per token.
        RateLimiter::for('seller-api', static fn (Request $request): Limit => Limit::perMinute(120)->by('seller-api:'.(is_object($t = $request->user()?->currentAccessToken()) && property_exists($t, 'id') ? $t->id : $request->ip())));
        RateLimiter::for('data-export', static fn (Request $request): Limit => Limit::perHour(3)->by('data-export:'.(is_scalar($id = $request->user()?->getAuthIdentifier()) ? 'u'.$id : 'ip'.$request->ip())));

        // Fail loudly in dev/test on lazy loading, silently discarded attributes and missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        Event::listen(Login::class, RecordSuccessfulLogin::class);

        // Notification matrix (spec 12.3): order and money events → inbox.
        Event::listen(OrderPaid::class, [SendOrderNotifications::class, 'paid']);
        Event::listen(OrderPaid::class, OpenOrderConversation::class);
        Event::listen(OrderDelivered::class, [SendOrderNotifications::class, 'delivered']);
        Event::listen(OrderCompleted::class, [SendOrderNotifications::class, 'completed']);
        Event::listen(OrderCompleted::class, [AffiliateListeners::class, 'completed']);
        Event::listen(OrderCompleted::class, [ReviewListeners::class, 'completed']);
        Event::listen(Registered::class, [AffiliateListeners::class, 'registered']);
        Event::listen(OrderCancelled::class, [SendOrderNotifications::class, 'cancelled']);
        Event::listen(OrderCancelled::class, RecordCancellationViolation::class);
        Event::listen(HoldReleased::class, [SendOrderNotifications::class, 'holdReleased']);
        Event::listen(Failed::class, RecordFailedLogin::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Certification::class, CertificationPolicy::class);
        Gate::policy(SellerLevel::class, SellerLevelPolicy::class);

        foreach ([SellerProfile::class, SellerServiceApplication::class, MerchantContact::class] as $sellerModel) {
            Gate::policy($sellerModel, SellerPolicy::class);
        }

        Gate::policy(Offer::class, OfferPolicy::class);
        Gate::policy(StorageGroup::class, OfferPolicy::class);

        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Coupon::class, MarketingPolicy::class);
        Gate::policy(OrderComment::class, OrderCommentPolicy::class);
        Gate::policy(AccountDeletionRequest::class, StaffReadOnlyPolicy::class);
        foreach ([HomeModule::class, HomeModuleItem::class, NewsArticle::class, HelpArticle::class, Page::class, SeoLinkRow::class] as $content) {
            Gate::policy($content, ContentPolicy::class);
        }
        foreach ([HomeModule::class, HomeModuleItem::class, NewsArticle::class, HelpArticle::class, Page::class, SeoLinkRow::class] as $content) {
            $content::saved(static fn () => HomeCache::flush());
            $content::deleted(static fn () => HomeCache::flush());
        }
        Gate::policy(Affiliate::class, MarketingPolicy::class);
        Gate::policy(AffiliateVideoRecord::class, MarketingPolicy::class);
        Gate::policy(CreativeAsset::class, MarketingPolicy::class);
        Gate::policy(Campaign::class, MarketingPolicy::class);
        Gate::policy(CampaignParticipation::class, MarketingPolicy::class);
        Gate::policy(LedgerJournal::class, LedgerPolicy::class);
        Gate::policy(LedgerAccount::class, LedgerPolicy::class);

        Gate::policy(Dispute::class, DisputePolicy::class);
        Gate::policy(Chargeback::class, ChargebackPolicy::class);
        Gate::policy(Violation::class, ViolationPolicy::class);
        Gate::policy(ViolationRule::class, ViolationPolicy::class);

        foreach ([Order::class, RiskFlag::class, BlocklistEntry::class] as $riskModel) {
            Gate::policy($riskModel, OrderPolicy::class);
        }

        foreach ([CategoryType::class, Game::class, GameCategory::class, Product::class, Platform::class, Region::class, ServiceType::class] as $catalogModel) {
            Gate::policy($catalogModel, CatalogPolicy::class);
            // Navigation caches (mega menu, Game Index) follow catalog edits immediately.
            $catalogModel::saved(static fn () => CatalogCache::flush());
            $catalogModel::deleted(static fn () => CatalogCache::flush());
        }

        Product::observe(SyncProductSearch::class);

        // Live chat (ADR-021): every new message (user, auto-reply, system line) pings the conversation channel after
        // commit. A broadcast failure (Reverb down) never breaks sending; clients fall back to polling.
        Message::created(static function (Message $message): void {
            DB::afterCommit(static function () use ($message): void {
                $publicId = Conversation::query()->whereKey($message->conversation_id)->value('public_id');
                if (! is_string($publicId)) {
                    return;
                }
                try {
                    ConversationUpdated::dispatch($publicId, $message->id);
                } catch (Throwable $e) {
                    Log::warning('Chat broadcast failed', ['conversation' => $publicId, 'error' => $e->getMessage()]);
                }
            });
        });

        // Live counters (docs/ADMIN_PLAN.md item 11): the bell and the chat badge of the users concerned, and the
        // staff queue counters, get a content-free signal after commit (Signals never throws).
        InboxItem::created(static function (InboxItem $item): void {
            Signals::user($item->user_id, 'inbox');
        });
        Message::created(static function (Message $message): void {
            Participant::query()->where('conversation_id', $message->conversation_id)
                ->when($message->sender_id !== null, fn ($q) => $q->where('user_id', '!=', $message->sender_id))
                ->pluck('user_id')
                ->each(static fn (mixed $userId) => is_numeric($userId) ? Signals::user((int) $userId, 'chat') : null);
            if ($message->body_redacted !== null) {
                Signals::staffQueues();
            }
        });
        foreach ([Order::class, RiskFlag::class, Dispute::class, Ticket::class, Offer::class, WithdrawalRequest::class, Certification::class, CampaignParticipation::class, AffiliateVideoRecord::class, LedgerAdjustment::class, SettingChange::class, PointsAdjustment::class] as $queued) {
            $queued::saved(static function (Model $model): void {
                if ($model->wasRecentlyCreated || $model->wasChanged('status')) {
                    Signals::staffQueues();
                }
            });
        }

        Gate::policy(Ticket::class, TicketPolicy::class);

        foreach ([WithdrawalRequest::class, PayoutMethodType::class, Invoice::class] as $payoutModel) {
            Gate::policy($payoutModel, PayoutPolicy::class);
        }
        Gate::policy(CannedReply::class, TicketPolicy::class);

        // Admins can do everything, except abilities that must hold for everyone (self-review).
        Gate::before(static function (User $user, string $ability, array $arguments): ?bool {
            if ($ability === 'review' && ($arguments[0] ?? null) instanceof Certification && $arguments[0]->user_id === $user->id) {
                return false;
            }

            // Nobody decides on their own store's service applications.
            if ($ability === 'moderate' && ($arguments[0] ?? null) instanceof SellerServiceApplication && $arguments[0]->profile->user_id === $user->id) {
                return false;
            }

            // …nor on their own store's offers.
            if ($ability === 'moderate' && ($arguments[0] ?? null) instanceof Offer && $arguments[0]->seller->user_id === $user->id) {
                return false;
            }

            // …nor on a payment review of an order they bought or sold.
            if ($ability === 'review' && ($arguments[0] ?? null) instanceof Order && OrderPolicy::involved($user, $arguments[0])) {
                return false;
            }

            // …nor on a dispute about their own order, or a violation of their own store.
            if ($ability === 'decide' && ($arguments[0] ?? null) instanceof Dispute && DisputePolicy::involved($user, $arguments[0])) {
                return false;
            }
            if ($ability === 'decide' && ($arguments[0] ?? null) instanceof Violation && $arguments[0]->seller->user_id === $user->id) {
                return false;
            }

            return $user->hasStaffRole(StaffRole::Admin) ? true : null;
        });
    }
}
