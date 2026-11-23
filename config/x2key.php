<?php

declare(strict_types=1);

/*
 * X2KEY platform configuration.
 *
 * `policy` holds the Part 27 defaults. Runtime values are read through
 * App\Shared\Support\Settings, which lets staff override any key in the
 * `settings` table without a deploy. Money values are integer minor units
 * of the settlement currency (USD cents).
 */

return [

    'brand' => [
        'name' => 'X2KEY',
    ],

    'support' => [
        'merchant_email' => env('MERCHANT_SERVICES_EMAIL', 'merchants@x2key.test'),
    ],

    'settlement_currency' => 'USD',

    'locales' => [
        'en' => ['name' => 'English', 'dir' => 'ltr'],
        'fr' => ['name' => 'Français', 'dir' => 'ltr'],
        'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
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
        'coupon_max_stack_platform' => 1,
        'coupon_max_stack_store' => 1,
        'withdrawal_min_amount' => 1000,
        'withdrawal_fee_amount' => 100,
        'manual_adjustment_dual_approval_amount' => 10000,
        'max_order_amount_new_buyer' => 5000,
        'risk_review_new_account_amount' => 5000,
        'online_threshold_seconds' => 120,
        'low_stock_default_threshold' => 5,
        'ticket_autoclose_hours' => 72,
        'violation_appeal_days' => 7,
        'violation_points_ttl_days' => 90,
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
