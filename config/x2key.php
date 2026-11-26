<?php

declare(strict_types=1);
use App\Domains\Payments\Providers\FakePaymentProvider;
use App\Domains\Payments\Providers\StripePaymentProvider;

/*
 * X2KEY platform configuration.
 *
 * `policy` holds the Part 27 defaults. Runtime values are read through
 * App\Shared\Support\Settings, which lets staff override any key in the
 * `settings` table without a deploy. Money values are integer minor units
 * of the settlement currency (USD cents).
 */

return [

    // Launch hardening (spec Part 23): send the CSP as Report-Only while trialling a change.
    'security' => [
        'csp_report_only' => (bool) env('CSP_REPORT_ONLY', false),
    ],

    // GET /health?token=… returns the detailed checks; without the token only the overall status (docs/RUNBOOK.md).
    'ops' => [
        'health_token' => env('HEALTH_TOKEN'),

        // Backups (docs/RUNBOOK.md §4): `ops:backup` nightly to this disk (an S3-compatible bucket in production,
        // separate from the app's own storage), kept this many days. The health check fails when the last good
        // backup is older than backup_max_age_hours, wherever backups are required (production by default).
        'backup_disk' => env('BACKUP_DISK', 'backups'),
        'backup_retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
        'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        'backups_required' => (bool) env('BACKUPS_REQUIRED', env('APP_ENV') === 'production'),

        // Alerts: a failing health check (and its recovery) goes to this e-mail and/or Slack incoming webhook.
        'alert_email' => env('OPS_ALERT_EMAIL'),
        'alert_slack_webhook' => env('OPS_SLACK_WEBHOOK'),
        'alert_repeat_minutes' => (int) env('OPS_ALERT_REPEAT_MINUTES', 60),
    ],

    // Web push (installable app, ADR-037): VAPID keys from `php artisan push:keys`. Empty = push switched off.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://x2key.com')),
    ],

    // Data retention per table (spec Part 23 "retention per table"), applied by `privacy:prune` daily.
    // Money, orders, journals, disputes and audit logs are never pruned.
    'retention_days' => [
        'browsing_history' => 180,
        'login_events' => 365,
        'user_sessions_revoked' => 90,
        'read_notifications' => 180,
        'risk_flags_resolved' => 730,
        'jobs_failed' => 30,
    ],

    'brand' => [
        'name' => 'X2KEY',
    ],

    'support' => [
        'merchant_email' => env('MERCHANT_SERVICES_EMAIL', 'merchants@x2key.test'),
        'customer_email' => env('CUSTOMER_SERVICES_EMAIL', 'support@x2key.test'),
        'dispute_email' => env('DISPUTE_SERVICES_EMAIL', 'disputes@x2key.test'),
    ],

    'settlement_currency' => 'USD',

    'locales' => [
        // content = sellers and staff type texts in this language (descriptions, notices, CMS). Interface-only
        // languages show the core screens translated and fall back to English per string (docs/RUNBOOK.md §9).
        'en' => ['name' => 'English', 'dir' => 'ltr', 'content' => true],
        'fr' => ['name' => 'Français', 'dir' => 'ltr', 'content' => true],
        'ar' => ['name' => 'العربية', 'dir' => 'rtl', 'content' => true],
        'es' => ['name' => 'Español', 'dir' => 'ltr', 'content' => false],
        'de' => ['name' => 'Deutsch', 'dir' => 'ltr', 'content' => false],
        'pt' => ['name' => 'Português', 'dir' => 'ltr', 'content' => false],
        'tr' => ['name' => 'Türkçe', 'dir' => 'ltr', 'content' => false],
    ],

    'default_locale' => 'en',

    // Listing search driver: 'database' (exact, any DB; dev/tests) or 'meilisearch' (production scale).
    'search' => [
        'driver' => env('CATALOG_SEARCH_DRIVER', 'database'),
        'meilisearch_index' => env('CATALOG_SEARCH_INDEX', 'products'),
    ],

    // Storage vault keys (spec 8.1, ADR-014): base64, 32 bytes each. Empty = derived from APP_KEY (local dev only).
    // Production sets both (KMS-held master key) and never changes the pepper (it keys the duplicate fingerprints).
    'storage' => [
        'master_key' => env('STORAGE_MASTER_KEY'),
        'hmac_pepper' => env('STORAGE_HMAC_PEPPER'),
        'key_id' => env('STORAGE_KEY_ID', 'v1'),
    ],

    // Payment providers by code (spec 9.1). Real PSP drivers join in M6b (D2). The fake PSP is refused in
    // production whatever this says.
    'payments' => [
        'providers' => [
            'fake' => FakePaymentProvider::class,
            'stripe' => StripePaymentProvider::class,
        ],
        // Stripe Checkout (M6b). Keys only in .env, never in the repository.
        'stripe' => [
            'key' => env('STRIPE_KEY', ''),
            'secret' => env('STRIPE_SECRET', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        ],
        'fake' => [
            // Signs fake webhooks; derived from APP_KEY when empty.
            'webhook_secret' => env('FAKE_PSP_WEBHOOK_SECRET'),
        ],
    ],

    // Display currencies only (D5): checkout and ledger always settle in USD.
    'display_currencies' => [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'BDT', 'BHD', 'BRL', 'CNY', 'CZK', 'EGP',
        'GHS', 'HUF', 'IDR', 'ILS', 'JPY', 'KRW', 'KWD', 'MAD', 'MYR', 'NGN', 'NPR',
        'NZD', 'OMR', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'SGD', 'THB', 'TRY', 'TWD',
    ],

    'policy' => [
        'payment_window_minutes' => 30,
        'accept_due_minutes' => 30,
        'default_delivery_time_minutes' => 60,
        'max_delivery_time_minutes' => 4320,
        'auto_cancel_after_sla_hours' => 24,
        'confirmation_window_hours' => 72,
        'confirmation_window_hours_services' => 168,
        'settlement_hold_days' => [
            'video_games' => 7, 'game_dlc' => 7, 'gaming_ecards' => 7,
            'gift_cards' => 7, 'egift_cards' => 7, 'retail_gift_cards' => 7,
            'top_up' => 3, 'mobile_recharges' => 3,
            'currency' => 3, 'items' => 3,
            'accounts' => 14,
            'boosting' => 7,
            'subscriptions' => 7, 'software_apps' => 7, 'coupons_savings' => 7,
        ],
        'account_recovery_protection_days' => 30,
        'new_seller_extra_hold_days' => 7,
        'new_seller_order_threshold' => 20,
        'rolling_reserve_bps_new_seller' => 1000,
        'reserve_release_days' => 60,
        'post_completion_dispute_days' => 14,
        'dispute_response_hours' => 72,
        'commission_bps' => 800,
        'buyer_fee_bps' => 300,
        'buyer_fee_min_amount' => 30,
        'refund_default_destination' => 'original_sources',
        'credits_topup_enabled' => false,
        'points_per_usd' => 10,
        'points_expiry_months' => null,
        'points_redeem_to_credits_enabled' => false, // D9: points → coupons only (see OPEN_QUESTIONS #1)
        'points_redeem_min' => 50000,
        'points_redeem_per_day' => 1,
        'affiliate_commission_bps' => 2000,
        'affiliate_level2_bps' => 300,
        'affiliate_attribution_days' => 90,
        'affiliate_transfer_min' => 1000,
        'affiliate_hold_extra_days' => 7, // commission held = order settlement hold + this (spec Part 27)
        'coupon_max_stack_platform' => 1,
        'coupon_max_stack_store' => 1,
        'withdrawal_min_amount' => 1000,
        'withdrawal_fee_amount' => 100,
        'manual_adjustment_dual_approval_amount' => 10000,
        'max_order_amount_new_buyer' => 5000,
        'risk_review_new_account_amount' => 5000,
        'risk_orders_per_hour_review' => 3,
        'risk_orders_per_hour_block' => 5,
        'risk_failed_payments_per_ip_block' => 5,
        'risk_new_buyer_days' => 7,
        'risk_review_score' => 50,
        'payment_sync_after_minutes' => 5,
        'online_threshold_seconds' => 120,
        'comment_window_days' => 30, // one comment per completed order within this window (spec Part 13)
        'low_stock_default_threshold' => 5,
        'ticket_autoclose_hours' => 72,
        'ticket_reopen_days' => 7,
        // First staff response targets by priority (spec Part 11 SLA timers).
        'ticket_sla_hours_high' => 4,
        'ticket_sla_hours_normal' => 24,
        'ticket_sla_hours_low' => 72,
        'violation_appeal_days' => 7,
        'violation_points_ttl_days' => 90,
        // Active (upheld, unexpired) violation points that suspend / ban a store (spec 17.2 thresholds).
        'violation_points_store_suspend' => 30,
        'violation_points_ban' => 60,
        'account_deletion_cooloff_days' => 14,
        'browsing_history_limit' => 50,
        'reveal_requires_reauth_minutes' => 30,
        'not_received_contact_hours' => 48,
        'not_received_report_hours' => 48,
        'listing_duration_days' => 7,
        'offer_expire_soon_hours' => 48,
        'storage_import_max_items' => 5000,
        'insurance_installments' => 3,
        // 0.1 cent per email; stored in milli-cents to stay integer.
        'marketing_email_unit_price_millicents' => 100,
        'marketing_email_daily_cap' => 1000,
        'marketing_email_monthly_cap' => 10000,
        'featured_offers_max' => 4,
        'store_video_max_mb' => 30,
    ],

];
