<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Searches that found nothing (docs/ADMIN_PLAN.md item 14), one row per normalised query and language, counted —
 * so staff can add search aliases to games. No user, IP or session is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_misses', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 100);
            $table->string('locale', 5);
            $table->unsignedInteger('hits')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable(); // alias added or dismissed
            $table->unique(['query', 'locale']);
            $table->index(['resolved_at', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_misses');
    }
};
