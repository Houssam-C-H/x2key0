<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display exchange rates (ADR-036): units of `currency` per 1 USD, refreshed daily by `fx:refresh`. Used only to
 * show approximate prices in the buyer's display currency; every charge, balance and payout stays in USD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->char('currency', 3)->primary();
            $table->decimal('per_usd', 20, 8);
            $table->string('source', 32);
            $table->timestampTz('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
