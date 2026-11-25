<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion requests (spec 5.1 / 6.8) and manual ledger adjustments with dual approval (spec 7.6, Part 27
 * `manual_adjustment_dual_approval_amount`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('reason', 500)->nullable();
            $table->string('status', 10); // requested | blocked | scheduled | completed | cancelled
            $table->json('blockers')->nullable();
            $table->json('factors'); // the two verification factors used
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
            $table->index('user_id');
        });

        Schema::create('ledger_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('account_code', 32);
            $table->unsignedBigInteger('owner_user_id')->nullable(); // user-owned accounts
            $table->string('offset_code', 32);                       // platform account on the other side
            $table->bigInteger('amount');                             // + credits the user / platform account (owed more), − debits it
            $table->char('currency', 3);
            $table->string('reason', 1000);
            $table->string('status', 10); // pending | posted | rejected
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
        Schema::dropIfExists('account_deletion_requests');
    }
};
