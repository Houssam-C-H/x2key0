<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement holds (spec 5.12 `holds`, 7.5) and the announcement centre (5.9 `announcements`, `user_inbox`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16)->default('user');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('order_id')->nullable()->constrained();
            // settlement: seller earnings after completion · insurance: one installment of an insured order ·
            // reserve: rolling reserve withheld at release, returned after `reserve_release_days`.
            $table->string('kind', 16);
            $table->unsignedSmallInteger('seq')->default(1);
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('reserve_bps')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('release_at');
            $table->timestampTz('released_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('source_hold_id')->nullable(); // reserve hold → the hold it was withheld from
            $table->timestampTz('created_at');
            $table->unique(['order_id', 'kind', 'seq', 'source_hold_id']);
            $table->index(['released_at', 'release_at']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->json('body');
            $table->string('audience', 16)->default('all'); // all | buyers | sellers | affiliates | segment
            $table->json('segment_query')->nullable();
            $table->string('category', 24)->default('announcement'); // todo | announcement | order | dispute | platform_service | merchant
            $table->timestampTz('publish_at');
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['publish_at', 'expires_at']);
        });

        Schema::create('user_inbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Set: read/star state of a platform announcement (materialised on first interaction).
            // Null: a system notification (orders, finance…) whose content is in `notification_type` + `data`.
            $table->foreignId('announcement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('notification_type', 48)->nullable();
            $table->string('category', 24)->default('order');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('starred_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'announcement_id']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'starred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_inbox');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('holds');
    }
};
