<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispute Center (spec Part 10, 5.10, 6.4), chargebacks (9.4) and the Violation Center (17.2, 5.17, 6.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('opened_by_id')->constrained('users');
            $table->string('opened_by_role', 8); // buyer | seller
            $table->string('reason', 24);
            $table->string('status', 24)->index();
            $table->string('requested_resolution', 16);
            $table->unsignedInteger('requested_qty');
            $table->boolean('refund_to_credits')->default(false);
            $table->text('description');
            $table->string('previous_substatus', 16)->nullable(); // restored when the dispute closes
            // Latest proposal of one party, waiting for the other to accept (spec Part 10 options).
            $table->string('proposal_resolution', 16)->nullable();
            $table->unsignedInteger('proposal_qty')->nullable();
            $table->string('proposal_by_role', 8)->nullable();
            $table->unsignedBigInteger('frozen_amount')->default(0); // moved SELLER_AVAILABLE → SELLER_FROZEN
            $table->timestampTz('response_due_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'status']);
        });

        Schema::create('dispute_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->string('author_role', 8); // buyer | seller | staff | system
            $table->text('body');
            $table->text('body_redacted')->nullable();
            $table->json('meta')->nullable(); // system lines: translation key + params
            $table->boolean('is_internal')->default(false);
            $table->timestampTz('created_at');
        });

        Schema::create('dispute_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('uploaded_by_role', 8);
            $table->string('file_path');
            $table->string('original_name', 191);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('description', 255)->nullable();
            $table->string('scan_status', 16)->default('pending');
            $table->timestampTz('created_at');
        });

        Schema::create('dispute_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->unique()->constrained();
            $table->foreignId('decided_by')->nullable()->constrained('users'); // null = agreement between the parties
            $table->string('outcome', 16); // refund_full | refund_partial | replacement | redelivery | no_refund | cancelled
            $table->unsignedInteger('refund_qty')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->string('refund_destination', 16)->nullable(); // original | credits
            $table->unsignedInteger('redeliver_qty')->default(0);
            $table->text('rationale')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('seller_fault')->default(false);
            $table->timestampTz('created_at');
        });

        Schema::create('dispute_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 8); // buyer | seller | staff | system
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('chargebacks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('payment_id')->constrained();
            $table->string('provider', 32);
            $table->string('provider_case_id', 191);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->char('currency', 3);
            $table->string('reason_code', 64)->nullable();
            $table->string('status', 8)->index(); // open | won | lost
            $table->unsignedBigInteger('frozen_amount')->default(0);    // moved SELLER_AVAILABLE → SELLER_FROZEN at open
            $table->unsignedBigInteger('recovered_amount')->default(0); // taken back from the seller / escrow when lost
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['provider', 'provider_case_id']);
        });

        // Holds of an order under dispute or chargeback do not release until it is closed.
        Schema::table('holds', function (Blueprint $table): void {
            $table->string('frozen_by', 32)->nullable()->after('source_hold_id'); // dispute:{id} | chargeback:{id}
        });

        Schema::create('violation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('points');
            $table->string('penalty', 16); // warning | listing_suspend | store_suspend | ban
            $table->boolean('auto')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('violations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_profile_id')->constrained();
            $table->foreignId('violation_rule_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('offer_id')->nullable()->constrained();
            $table->foreignId('dispute_id')->nullable()->constrained();
            $table->unsignedSmallInteger('points');
            $table->string('details', 500)->nullable();
            $table->string('status', 12)->index(); // open | appealed | upheld | revoked
            $table->text('appeal_text')->nullable();
            $table->timestampTz('appealed_at')->nullable();
            $table->foreignId('appeal_decided_by')->nullable()->constrained('users');
            $table->string('decision_note', 500)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('appeal_due_at');
            $table->timestampTz('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users'); // null = automatic rule
            $table->timestampsTz();
            $table->unique(['violation_rule_id', 'order_id', 'dispute_id']); // an automatic rule fires once per case
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE dispute_decisions ADD CONSTRAINT dispute_decisions_qty_ok CHECK (refund_qty >= 0 AND redeliver_qty >= 0);
                ALTER TABLE chargebacks ADD CONSTRAINT chargebacks_amount_positive CHECK (amount > 0);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('violations');
        Schema::dropIfExists('violation_rules');
        Schema::table('holds', function (Blueprint $table): void {
            $table->dropColumn('frozen_by');
        });
        Schema::dropIfExists('chargebacks');
        Schema::dropIfExists('dispute_status_history');
        Schema::dropIfExists('dispute_decisions');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};
