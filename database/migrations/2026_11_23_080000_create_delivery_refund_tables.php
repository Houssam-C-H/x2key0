<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery (spec 5.8 / 8.2 / 8.3), refunds (5.7) and auto-delivery logs (5.5). Delivered secrets are sealed
 * with SecretVault (ADR-014); evidence files live on the private disk with their SHA-256.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('method', 24);
            $table->string('delivered_by_type', 16); // system | seller
            $table->unsignedBigInteger('delivered_by_id')->nullable();
            $table->string('note', 1000)->nullable(); // transaction id, gift id, instructions (no secrets)
            $table->timestampTz('created_at');
        });

        Schema::create('delivery_storage_items', function (Blueprint $table): void {
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_item_id')->unique()->constrained(); // a storage item is delivered once (invariant 4.3 #4)
        });

        Schema::create('delivery_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // key | account | text
            $table->text('ciphertext');
            $table->text('dek_wrapped');
            $table->timestampTz('created_at');
        });

        Schema::create('delivery_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('pending'); // pending | clean | infected (scanner: follow-up)
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('service_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedTinyInteger('percent'); // cumulative progress after this milestone
            $table->string('status', 16)->default('done'); // pending | done | accepted
            $table->string('note', 1000)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('secret_reveals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_secret_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at');
            $table->index(['order_item_id', 'created_at']);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->string('destination', 16); // original_method | credits
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('reason', 255);
            $table->string('status', 16); // requested | processing | succeeded | failed
            $table->string('provider_refund_id', 128)->nullable();
            $table->string('initiated_by_type', 16);
            $table->unsignedBigInteger('initiated_by_id')->nullable();
            $table->unsignedBigInteger('dispute_id')->nullable(); // M11
            $table->timestampsTz();
            $table->index(['order_id', 'id']);
        });

        Schema::create('auto_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained();
            $table->foreignId('seller_profile_id')->constrained();
            $table->timestampTz('delivered_at');
            $table->string('result', 8); // success | failed
            $table->string('error', 255)->nullable();
            $table->index(['seller_profile_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_credits_amount')->default(0)->after('paid_credits_amount');
            $table->unsignedBigInteger('refunded_psp_amount')->default(0)->after('refunded_credits_amount');
            $table->unsignedBigInteger('refunded_fee_amount')->default(0)->after('refunded_psp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['refunded_credits_amount', 'refunded_psp_amount', 'refunded_fee_amount']);
        });

        Schema::dropIfExists('auto_delivery_logs');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('secret_reveals');
        Schema::dropIfExists('service_milestones');
        Schema::dropIfExists('delivery_evidence');
        Schema::dropIfExists('delivery_secrets');
        Schema::dropIfExists('delivery_storage_items');
        Schema::dropIfExists('deliveries');
    }
};
