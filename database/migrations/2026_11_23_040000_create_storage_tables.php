<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller inventory vault (spec 5.5 / Part 8.1). Secrets are envelope-encrypted by SecretVault: the
 * ciphertext and the wrapped data key are stored base64-encoded in text columns (portable across
 * SQLite and PostgreSQL, where bytea would come back as a stream). See ADR-014.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('item_type', 16);
            $table->unsignedInteger('low_stock_threshold');
            $table->unsignedInteger('available_count_cached')->default(0);
            $table->timestamps();
            $table->unique(['seller_profile_id', 'name']);
        });

        Schema::create('storage_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 8); // paste | file | form
            $table->unsignedInteger('count_total');
            $table->unsignedInteger('count_duplicates');
            $table->unsignedInteger('count_invalid');
            $table->unsignedInteger('count_flagged');
            $table->unsignedInteger('count_accepted');
            $table->timestamp('created_at');
        });

        Schema::create('storage_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_group_id')->constrained()->cascadeOnDelete();
            // Denormalised so duplicates can be refused per seller with a unique index.
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('available');
            $table->text('payload_ciphertext');
            $table->text('payload_dek_wrapped');
            $table->char('payload_hmac', 64);
            $table->json('meta')->nullable(); // never secrets
            // Cross-seller duplicate (spec 8.1 / Part 13): frozen until risk review, not counted as stock.
            $table->timestamp('flagged_at')->nullable();
            $table->unsignedBigInteger('reserved_for_order_item_id')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->unsignedBigInteger('assigned_order_item_id')->nullable()->unique();
            $table->foreignId('import_batch_id')->nullable()->constrained('storage_import_batches')->nullOnDelete();
            $table->timestamp('recycled_at')->nullable();
            $table->timestamps();
            $table->unique(['seller_profile_id', 'payload_hmac']);
            $table->index('payload_hmac');
            $table->index(['storage_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_items');
        Schema::dropIfExists('storage_import_batches');
        Schema::dropIfExists('storage_groups');
    }
};
