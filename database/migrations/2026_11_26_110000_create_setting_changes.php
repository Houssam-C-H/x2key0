<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform settings changes made from the admin screen (docs/ADMIN_PLAN.md item 1). Every change keeps its reason;
 * money settings wait for a second admin (status pending → applied | rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->boolean('is_reset')->default(false); // back to the config default
            $table->string('reason', 500);
            $table->string('status', 10); // pending | applied | rejected
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'key']);
            $table->index('requested_by');
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
    }
};
