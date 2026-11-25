<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff switch for a seller's Storage (ADR-034): while off, the seller cannot add or change Storage items, their
 * groups count as empty, so auto-delivery offers go out of stock until it is switched back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->boolean('storage_enabled')->default(true)->after('payouts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->dropColumn('storage_enabled');
        });
    }
};
