<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (platform + store), claims and redemptions, marketing campaigns and paid marketing e-mails (spec 5.14,
 * Part 14, 1.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('issuer', 8);                                   // platform | seller
            $table->foreignId('seller_profile_id')->nullable()->constrained();
            $table->string('code', 32)->nullable();                         // store codes (upper-case)
            $table->boolean('is_public')->default(true);                    // store: shown on the store page; private = "Recommend"
            $table->json('name');
            $table->string('type', 8);                                      // fixed | percent
            $table->unsignedBigInteger('value');                            // fixed: minor units · percent: bps
            $table->unsignedBigInteger('max_discount_amount')->nullable();  // cap for percent platform coupons
            $table->unsignedBigInteger('min_order_amount')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->json('scope')->nullable();                              // {"game_ids": [], "category_types": [], "game_category_ids": []}
            $table->unsignedInteger('claim_limit_total')->nullable();
            $table->unsignedInteger('claim_limit_per_user')->default(1);
            $table->unsignedInteger('claimed_count')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedBigInteger('points_cost')->nullable();          // platform coupons bought with X2K Score
            $table->unsignedSmallInteger('user_level_min')->nullable();     // buyer level rank
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->unsignedSmallInteger('valid_days_after_claim')->nullable();
            $table->string('status', 8)->index();                           // draft | active | paused | ended
            $table->boolean('stackable')->default(true);
            $table->timestampsTz();
            $table->unique(['seller_profile_id', 'code']);
            $table->index(['issuer', 'status']);
        });

        Schema::create('coupon_claims', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('coupon_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestampTz('claimed_at');
            $table->timestampTz('expires_at')->nullable();
            $table->string('status', 10)->index();                          // available | used | expired | revoked
            $table->foreignId('used_order_id')->nullable()->constrained('orders');
            $table->string('source', 12)->default('claim');                 // claim | points | gift | email
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        Schema::create('order_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('coupon_claim_id')->unique()->constrained();  // invariant 4.3 #8: one redemption per claim
            $table->unsignedBigInteger('amount');
            $table->string('funded_by', 8);                                 // platform | seller
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_discount_platform_amount')->default(0)->after('refunded_fee_amount');
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('type', 20);                                     // discount_event | featured_slot | flash_sale | new_game_launch
            $table->json('rules')->nullable();                              // e.g. {"min_discount_bps": 1000, "game_ids": []}
            $table->unsignedSmallInteger('platform_subsidy_bps')->default(0);
            $table->unsignedSmallInteger('max_offers_per_seller')->default(10);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 10)->index();                          // draft | active | ended
            $table->timestampsTz();
        });

        Schema::create('campaign_participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained();
            $table->json('offer_ids');
            $table->string('status', 10)->index();                          // applied | approved | rejected | withdrawn
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'seller_profile_id']);
        });

        Schema::create('marketing_emails', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_profile_id')->constrained();
            $table->string('type', 16);                                     // new_product | limited_discount | loyal_customer | clearance
            $table->json('offer_ids');
            $table->foreignId('coupon_id')->nullable()->constrained();
            $table->unsignedInteger('recipient_count');
            $table->unsignedInteger('unit_price_millicents');
            $table->unsignedBigInteger('total_amount');                     // cents, rounded up
            $table->string('status', 10)->index();                          // sent | settled | unsettled
            $table->timestampTz('sent_at');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE coupons ADD CONSTRAINT coupons_value_positive CHECK (value > 0);
                ALTER TABLE orders ADD CONSTRAINT orders_promo_refund_ok CHECK (refunded_discount_platform_amount <= discount_platform_amount);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_emails');
        Schema::dropIfExists('campaign_participations');
        Schema::dropIfExists('campaigns');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_discount_platform_amount');
        });
        Schema::dropIfExists('order_coupon_redemptions');
        Schema::dropIfExists('coupon_claims');
        Schema::dropIfExists('coupons');
    }
};
