<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price alerts: "tell me when this is below $X" on a catalog product or a game×category (offer listings).
 * `notified_at` is set when the alert fires and cleared when the price goes back above the target (re-armed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('game_category_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('target_amount');
            $table->char('currency', 3)->default('USD');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'product_id']);
            $table->unique(['user_id', 'game_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
