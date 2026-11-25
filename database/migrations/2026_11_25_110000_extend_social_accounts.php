<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social login (ADR-033): `social_accounts` exists since the identity tables (provider + provider user id, unique per
 * user and provider, linked_at). Add the linked e-mail (shown in Settings) and when it was last used to log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('provider_user_id');
            $table->timestampTz('last_used_at')->nullable()->after('linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['email', 'last_used_at']);
        });
    }
};
