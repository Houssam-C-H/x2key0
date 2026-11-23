<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service tickets (spec Part 11, 5.10, 6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('topic', 24);
            $table->string('subject', 150);
            $table->json('form_data')->nullable();                  // topic follow-up answers (e.g. catalog request)
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('dispute_id')->nullable()->constrained(); // set when staff convert the ticket
            $table->string('priority', 8)->default('normal');
            $table->string('status', 16)->index();                   // pending | awaiting_reply | closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_due_at')->nullable(); // SLA target while waiting for staff
            $table->timestampTz('first_responded_at')->nullable();
            $table->timestampTz('last_reply_at')->nullable();
            $table->timestampTz('last_staff_reply_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_by', 8)->nullable();             // user | staff | system
            $table->unsignedTinyInteger('satisfaction')->nullable(); // CSAT 1–5 after close
            $table->string('satisfaction_comment', 500)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'first_response_due_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->string('author_role', 8); // user | staff | system
            $table->text('body');
            $table->json('meta')->nullable(); // system lines: translation key + params
            $table->boolean('is_internal')->default(false);
            $table->timestampTz('created_at');
        });

        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name', 191);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('pending');
            $table->timestampTz('created_at');
        });

        Schema::create('ticket_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('actor_type', 8);
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('canned_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->string('topic', 24)->nullable();
            $table->json('body'); // {"en": "...", "fr": "...", "ar": "..."}
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('ticket_status_history');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
