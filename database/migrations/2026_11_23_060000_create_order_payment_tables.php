<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout & payments (spec 5.6, 5.7, 6.1 up to `paid`, 9, 17.1). Money = bigint minor units + currency.
 * Buyer inputs are sealed by SecretVault (ADR-014): ciphertext + wrapped key, base64 text.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gap-free per-day counter for X2K{yyMMdd}{seq} order numbers.
        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->char('day', 6)->primary();
            $table->unsignedInteger('last_value')->default(0);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 24)->unique();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_profile_id')->constrained();
            $table->string('idempotency_key', 64);
            $table->string('status', 24)->index();
            $table->string('seller_substatus', 16)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_platform_amount')->default(0);
            $table->unsignedBigInteger('discount_seller_amount')->default(0);
            $table->unsignedBigInteger('buyer_fee_amount')->default(0);
            $table->unsignedBigInteger('addon_fee_amount')->default(0);
            $table->unsignedBigInteger('method_fee_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('paid_psp_amount')->default(0);
            $table->unsignedBigInteger('paid_credits_amount')->default(0);
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('seller_net_amount');
            $table->unsignedBigInteger('affiliate_id')->nullable(); // FK with M15
            $table->text('buyer_inputs_ciphertext')->nullable();
            $table->text('buyer_inputs_dek_wrapped')->nullable();
            $table->char('buyer_country', 2)->nullable();
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->string('risk_decision', 8)->nullable();
            $table->text('seller_internal_remark')->nullable();
            $table->unsignedBigInteger('seller_acquisition_cost_amount')->nullable();
            $table->text('buyer_internal_remark')->nullable();
            $table->timestampTz('payment_due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('delivery_due_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('confirm_due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestampTz('settle_after')->nullable();
            $table->timestampsTz();
            // A double-submitted checkout returns the same order.
            $table->unique(['buyer_id', 'idempotency_key']);
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_profile_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedInteger('delivered_qty')->default(0);
            $table->unsignedInteger('refunded_qty')->default(0);
            // Manual-stock units taken from the offer at checkout (returned if the order is cancelled).
            $table->unsignedInteger('reserved_manual_qty')->default(0);
            $table->json('snapshot'); // frozen copy of the offer ("view snapshot", spec 1.6)
            $table->timestampsTz();
        });

        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('from_substatus', 16)->nullable();
            $table->string('to_substatus', 16)->nullable();
            $table->string('actor_type', 16); // buyer | seller | staff | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('created_at');
            $table->index(['order_id', 'id']);
        });

        Schema::create('order_timers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestampTz('due_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unique(['order_id', 'type']);
            $table->index(['type', 'processed_at', 'due_at']);
        });

        Schema::create('payment_methods_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->string('logo_path')->nullable();
            $table->string('provider', 32);
            $table->json('countries')->nullable();  // null = everywhere
            $table->json('currencies');
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->unsignedSmallInteger('fee_bps')->default(0);
            $table->unsignedBigInteger('fee_fixed')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('purpose', 16)->default('order');
            $table->string('provider', 32);
            $table->string('method_code', 32);
            $table->string('provider_payment_id', 128)->nullable()->unique();
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->boolean('three_ds')->default(false);
            $table->string('card_bin', 8)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->char('card_country', 2)->nullable();
            $table->string('redirect_url', 512)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_event_id', 128);
            $table->string('type', 32);
            $table->json('payload');
            $table->boolean('signature_valid');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('error', 255)->nullable();
            // A provider event is processed at most once (invariant 4.3 #5).
            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('risk_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('rule', 48);
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'rule']);
        });

        Schema::create('blocklist', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16); // email_domain | ip | card_bin | device | payload_hmac
            $table->string('value', 128);
            $table->string('reason', 255)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['type', 'value']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Never sell what is not there, whatever the code does.
            DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_manual_stock_non_negative CHECK (manual_stock_qty IS NULL OR manual_stock_qty >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_consistent CHECK (delivered_qty + refunded_qty <= quantity)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_paid_not_above_total CHECK (paid_psp_amount + paid_credits_amount <= total_amount)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_manual_stock_non_negative');
        }

        Schema::dropIfExists('blocklist');
        Schema::dropIfExists('risk_flags');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_methods_catalog');
        Schema::dropIfExists('order_timers');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_number_sequences');
    }
};
