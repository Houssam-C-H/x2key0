<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawal Management, payout methods, Invoice info, service invoices and withdrawal vouchers (spec 1.8, 5.16,
 * 6.6, Part 16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_method_catalog', function (Blueprint $table): void {
            $table->string('code', 24)->primary();
            $table->string('name', 64);
            $table->json('currencies');
            $table->json('fields'); // detail fields the user fills in, e.g. ["email"] or ["account_holder","iban","swift"]
            $table->unsignedSmallInteger('fee_bps')->default(0);
            $table->unsignedBigInteger('fee_fixed_amount')->default(0);
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('payout_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 24);
            $table->char('payout_currency', 3);
            $table->text('details_encrypted');
            $table->string('masked_label', 64);
            $table->timestampTz('verified_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampTz('disabled_at')->nullable(); // removed by the user (kept for past withdrawals)
            $table->timestampsTz();
            $table->index(['user_id', 'disabled_at']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('source', 12)->default('seller'); // seller | affiliate (M15)
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('payout_amount');
            $table->char('payout_currency', 3);
            $table->foreignId('payout_method_id')->constrained();
            $table->string('payout_label', 64); // masked destination at request time
            $table->string('status', 12)->index();
            $table->string('remarks', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('provider_ref', 191)->nullable();
            $table->string('idempotency_key', 64);
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('withdrawal_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->string('actor_type', 8);
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('invoice_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('legal_name', 150);
            $table->text('tax_id_encrypted')->nullable();
            $table->json('address');
            $table->string('email', 191);
            $table->timestampsTz();
        });

        // Sequential numbering per prefix and year (Part 16): one locked counter row per (prefix, year).
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->string('prefix', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['prefix', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->unique();
            $table->string('type', 20); // service_invoice | withdrawal_voucher
            $table->foreignId('user_id')->constrained();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('lines');
            $table->json('billed_to');                 // Invoice info snapshot at issue time
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->char('currency', 3);
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['type', 'source_type', 'source_id']);
            $table->index(['user_id', 'type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE withdrawal_requests ADD CONSTRAINT withdrawal_amounts_ok CHECK (amount > 0 AND fee_amount < amount AND payout_amount = amount - fee_amount);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoice_profiles');
        Schema::dropIfExists('withdrawal_status_history');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('payout_methods');
        Schema::dropIfExists('payout_method_catalog');
    }
};
