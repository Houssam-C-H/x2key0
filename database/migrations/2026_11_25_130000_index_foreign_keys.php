<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed pass (ADR-035): PostgreSQL does not index foreign keys by itself. Every single-column foreign key that was
 * not the leading column of an index gets one, so child lookups (an order's items, a dispute's messages, a
 * journal's postings…) and cascading deletes never scan whole tables. Rule from now on: every foreign key is indexed.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array FOREIGN_KEYS = [
        'affiliate_commissions' => ['order_id'],
        'affiliate_links' => ['affiliate_id'],
        'affiliate_referrals' => ['affiliate_link_id'],
        'affiliate_video_records' => ['reviewed_by'],
        'affiliates' => ['parent_affiliate_id'],
        'announcements' => ['created_by'],
        'auto_delivery_logs' => ['offer_id', 'order_item_id', 'storage_item_id'],
        'browsing_history' => ['offer_id', 'product_id'],
        'campaign_participations' => ['decided_by', 'seller_profile_id'],
        'certifications' => ['reviewed_by'],
        'chargebacks' => ['closed_by', 'order_id', 'payment_id'],
        'coupon_claims' => ['coupon_id', 'used_order_id'],
        'deliveries' => ['order_item_id'],
        'delivery_evidence' => ['delivery_id', 'uploaded_by'],
        'delivery_secrets' => ['delivery_id'],
        'delivery_storage_items' => ['delivery_id'],
        'dispute_decisions' => ['decided_by'],
        'dispute_evidence' => ['dispute_id', 'uploaded_by'],
        'dispute_messages' => ['author_id', 'dispute_id'],
        'dispute_status_history' => ['actor_id', 'dispute_id'],
        'disputes' => ['opened_by_id', 'order_item_id'],
        'game_categories' => ['category_type_id'],
        'ledger_adjustments' => ['approved_by', 'requested_by'],
        'ledger_postings' => ['journal_id'],
        'low_stock_alerts' => ['storage_group_id'],
        'marketing_emails' => ['coupon_id', 'seller_profile_id'],
        'merchant_contacts' => ['seller_profile_id', 'staff_user_id'],
        'news_articles' => ['author_id', 'game_id'],
        'offer_price_history' => ['changed_by', 'offer_id'],
        'offer_status_changes' => ['actor_id', 'offer_id'],
        'offers' => ['duplicate_of_offer_id', 'service_type_id', 'storage_group_id'],
        'order_comments' => ['hidden_by', 'offer_id', 'product_id'],
        'order_coupon_redemptions' => ['order_id'],
        'order_items' => ['offer_id', 'order_id', 'product_id'],
        'orders' => ['affiliate_id'],
        'payments' => ['order_id'],
        'points_postings' => ['journal_id'],
        'products' => ['platform_id', 'region_id'],
        'refunds' => ['payment_id'],
        'risk_flags' => ['resolved_by'],
        'role_has_permissions' => ['role_id'],
        'secret_reveals' => ['delivery_secret_id', 'storage_item_id', 'user_id'],
        'seller_profiles' => ['merchant_manager_id', 'seller_level_id'],
        'seller_service_applications' => ['audited_by', 'game_category_id'],
        'seller_status_changes' => ['actor_id', 'seller_profile_id'],
        'service_milestones' => ['order_item_id'],
        'settings' => ['updated_by'],
        'storage_import_batches' => ['storage_group_id', 'uploaded_by'],
        'storage_items' => ['import_batch_id'],
        'ticket_attachments' => ['ticket_id', 'ticket_message_id'],
        'ticket_messages' => ['author_id', 'ticket_id'],
        'ticket_status_history' => ['actor_id', 'ticket_id'],
        'tickets' => ['assigned_to', 'dispute_id', 'order_id'],
        'user_inbox' => ['announcement_id'],
        'user_sessions' => ['user_device_id'],
        'users' => ['user_level_id'],
        'violations' => ['appeal_decided_by', 'created_by', 'dispute_id', 'offer_id', 'order_id', 'seller_profile_id'],
        'withdrawal_requests' => ['payout_method_id', 'reviewed_by'],
        'withdrawal_status_history' => ['actor_id', 'withdrawal_request_id'],
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->index($column, "{$table}_{$column}_fk_index");
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::FOREIGN_KEYS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropIndex("{$table}_{$column}_fk_index");
                    }
                }
            });
        }
    }
};
