<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offers (spec 5.4, state machine 6.2), low-stock alerts (5.5) and engagement (5.17: favorites,
 * browsing history). Offer attribute values live in `attribute_values` (ADR-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->json('description')->nullable();
            $table->string('delivery_method', 24);
            $table->string('fulfillment', 8);
            $table->foreignId('storage_group_id')->nullable()->constrained()->nullOnDelete();
            $table->json('attribute_values')->nullable();
            $table->char('attributes_hash', 64);
            $table->unsignedBigInteger('unit_price_amount');
            $table->char('currency', 3);
            $table->unsignedInteger('min_qty')->default(1);
            $table->unsignedInteger('max_qty')->nullable();
            $table->string('unit_label', 40)->nullable();
            $table->boolean('integer_multiple')->default(false);
            $table->json('quantity_tiers')->nullable();
            $table->unsignedInteger('delivery_time_minutes');
            $table->unsignedInteger('manual_stock_qty')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('account_recovery_protection_days')->nullable();
            $table->unsignedSmallInteger('insurance_days')->default(0);
            $table->string('insurance_mode', 12)->default('none');
            $table->integer('sort_weight')->default(0);
            $table->unsignedBigInteger('acquisition_cost_amount')->nullable();
            $table->string('publish_mode', 12)->default('immediate');
            $table->unsignedSmallInteger('listing_days');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('status_reason', 500)->nullable(); // rejection / system deactivation reason shown to the seller
            // Same seller + product/category + delivery method + attributes as another live offer (spec 5.4).
            $table->foreignId('duplicate_of_offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['seller_profile_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['game_category_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_unit_price_positive CHECK (unit_price_amount > 0)');
        }

        // Append-only history of offer status transitions (6.2, invariant 4.3 #7).
        Schema::create('offer_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('offer_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('unit_price_amount');
            $table->char('currency', 3);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('low_stock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('triggered_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->index(['seller_profile_id', 'resolved_at']);
            $table->index(['offer_id', 'resolved_at']);
        });

        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('favoritable_type', 16);
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
        });

        Schema::create('browsing_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable()->index();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browsing_history');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('low_stock_alerts');
        Schema::dropIfExists('offer_price_history');
        Schema::dropIfExists('offer_status_changes');
        Schema::dropIfExists('offers');
    }
};
