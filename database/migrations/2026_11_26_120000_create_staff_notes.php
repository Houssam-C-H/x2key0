<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff notes on a user, store or order (docs/ADMIN_PLAN.md items 3, 4, 10). Never shown to the user;
 * append-only like the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32); // user | seller | order
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notes');
    }
};
