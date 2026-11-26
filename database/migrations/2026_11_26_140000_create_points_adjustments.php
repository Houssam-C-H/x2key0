<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual X2K Score adjustments (docs/ADMIN_PLAN.md item 7): posted through the points ledger as `adjust` journals;
 * at or above `points_adjustment_dual_approval` they wait for a second staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->bigInteger('points'); // + gives points, − takes them back
            $table->string('reason', 1000);
            $table->string('status', 10); // pending | posted | rejected
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
            $table->index('user_id');
            $table->index('requested_by');
            $table->index('approved_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_adjustments');
    }
};
