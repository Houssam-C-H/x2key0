<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity & certification tables (spec 5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64); // sha256 of the long-lived device cookie
            $table->string('name')->nullable(); // "Chrome on Windows"
            $table->timestampTz('trusted_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'fingerprint']);
        });

        // Append-only login history, including failures.
        Schema::create('login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identifier')->nullable(); // what was typed, for failed attempts
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->char('country', 2)->nullable();
            $table->boolean('succeeded');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        // Driver-independent session registry (works with Redis sessions) for list & revoke.
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 128)->unique();
            $table->foreignId('user_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_active_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('bookmark_prompt_dismissed')->default(false);
            $table->boolean('notification_mute')->default(false);
            $table->boolean('seller_promo_emails')->default(true);
            $table->json('email_prefs')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_security', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('privacy_password_hash')->nullable();
            $table->timestampTz('privacy_password_set_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_messengers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('handle', 128);
            $table->timestampsTz();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id');
            $table->timestampTz('linked_at')->useCurrent();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('version', 16);
            $table->timestampTz('signed_at');
            $table->string('ip', 45)->nullable();
            $table->unique(['user_id', 'type', 'version']);
        });

        Schema::create('certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('none');
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref')->nullable();
            $table->text('data_encrypted')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'type']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['certifications', 'contracts', 'social_accounts', 'user_messengers', 'user_security', 'user_preferences', 'user_sessions', 'login_events', 'user_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
