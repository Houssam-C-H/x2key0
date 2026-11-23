<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity core (spec 5.1). Rewritten from the Laravel skeleton before any data existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->bigInteger('min_spend_amount')->default(0); // USD cents, lifetime completed spend
            $table->integer('points_multiplier_bps')->default(10000);
            $table->string('icon_path')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('username', 32)->unique();
            $table->string('name', 64); // nickname shown to others
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 8)->default('en');
            $table->char('display_currency', 3)->default('USD');
            $table->char('country', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->foreignId('user_level_id')->nullable()->constrained('user_levels')->nullOnDelete();
            $table->string('status', 16)->default('active')->index();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->unsignedBigInteger('referred_by_affiliate_id')->nullable()->index(); // FK added in M15
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_levels');
    }
};
