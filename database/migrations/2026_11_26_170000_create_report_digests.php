<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly report e-mails already sent (docs/ADMIN_PLAN.md item 18): one row per ISO week, so a re-run of the
 * scheduled job never mails the same summary twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_digests', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 16)->unique(); // e.g. 2026-W47
            $table->unsignedInteger('recipients');
            $table->timestampTz('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_digests');
    }
};
