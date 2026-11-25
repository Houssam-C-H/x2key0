<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service types (ADR-032): the kinds of service sold inside a category type ("Rank boost", "Coaching" under
 * Boosting), switched on and off by staff. An offer in a category type that has enabled service types names one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_type_id')->constrained()->restrictOnDelete();
            $table->string('code', 48);
            $table->json('name');
            $table->json('description')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestampsTz();
            $table->unique(['category_type_id', 'code']);
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->foreignId('service_type_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_type_id');
        });
        Schema::dropIfExists('service_types');
    }
};
