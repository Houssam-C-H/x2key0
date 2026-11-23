<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chat (spec 5.9 / 12.1): conversations, participants (read marker), messages (original + redacted body). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('type', 12); // order | pre_sale | support
            $table->foreignId('order_id')->nullable()->unique()->constrained();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_profile_id')->nullable()->constrained();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();
            $table->index(['buyer_id', 'last_message_at']);
            $table->index(['seller_profile_id', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->boolean('muted')->default(false);
            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users');
            $table->string('sender_role', 8); // buyer | seller | system | staff
            $table->string('kind', 12)->default('text'); // text | system | auto_reply
            $table->text('body');
            $table->text('body_redacted')->nullable(); // set when contact details were hidden from the counterparty
            $table->json('flags')->nullable();
            $table->timestampTz('created_at');
            $table->index(['conversation_id', 'id']);
            $table->index(['sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
