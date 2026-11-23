<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers & store (spec 5.3, state machine 6.3, service applications 6.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->unsignedTinyInteger('stars')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedSmallInteger('hold_days');
            $table->unsignedSmallInteger('reserve_bps');
            $table->unsignedInteger('max_active_offers');
            $table->boolean('auto_approve')->default(false);
            $table->smallInteger('rank_boost')->default(0);
            // Entry thresholds used by the level recompute job (M16).
            $table->unsignedInteger('min_orders_completed')->default(0);
            $table->unsignedSmallInteger('min_completion_rate_bps')->default(0);
            $table->timestamps();
        });

        Schema::create('seller_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('store_name', 40)->unique();
            $table->string('slug', 48)->unique();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->json('bio')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('status', 16)->default('inactive')->index();
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('seller_level_id')->nullable()->constrained('seller_levels')->nullOnDelete();
            $table->boolean('level_locked')->default(false); // staff override: recompute job leaves it alone
            $table->boolean('payouts_enabled')->default(false);
            $table->foreignId('merchant_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('auto_reply')->nullable();
            $table->json('store_notice')->nullable();
            $table->timestamp('vacation_until')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        // Append-only history of seller status transitions (6.3).
        Schema::create('seller_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('seller_stats', function (Blueprint $table): void {
            $table->foreignId('seller_profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_cancelled_by_seller')->default(0);
            $table->unsignedInteger('disputes_opened')->default(0);
            $table->unsignedInteger('disputes_lost')->default(0);
            $table->unsignedSmallInteger('completion_rate_bps')->nullable();
            $table->unsignedSmallInteger('positive_rate_bps')->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('avg_delivery_minutes')->nullable();
            $table->unsignedBigInteger('sales_30d_amount')->default(0); // USD cents
            $table->unsignedInteger('violation_points')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('seller_service_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_category_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['seller_profile_id', 'game_category_id']);
        });

        Schema::create('seller_store_media', function (Blueprint $table): void {
            $table->foreignId('seller_profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('video_path')->nullable();
            $table->json('languages')->nullable();          // [{code: "en", level: "native"}]
            $table->json('featured_offer_ids')->nullable(); // list of offer ids (≤ featured_offers_max), M5
            $table->timestamps();
        });

        Schema::create('merchant_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('value', 191);
            $table->string('hours', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_contacts');
        Schema::dropIfExists('seller_store_media');
        Schema::dropIfExists('seller_service_applications');
        Schema::dropIfExists('seller_stats');
        Schema::dropIfExists('seller_status_changes');
        Schema::dropIfExists('seller_profiles');
        Schema::dropIfExists('seller_levels');
    }
};
