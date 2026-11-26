<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sent e-mail log for the admin (docs/ADMIN_PLAN.md item 12): who got which mail when — never the body. Pruned after
 * `x2key.retention_days.mail_log` by `privacy:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table): void {
            $table->id();
            $table->string('to', 255);
            $table->string('subject', 255);
            $table->string('mailable', 120)->nullable();
            $table->timestampTz('sent_at');
            $table->index('sent_at');
            $table->index('to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
