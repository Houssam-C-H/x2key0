<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game-centric catalog (spec 5.2): CategoryType → Game × CategoryType (GameCategory) → Product → Offer (M5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('name');
            $table->string('icon', 32)->nullable();
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->json('default_policy')->nullable();
            $table->timestampsTz();
        });

        Schema::create('platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 48)->unique();
            $table->string('name', 64);
            $table->string('icon_path')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique(); // GLOBAL, EU, US, TR, …
            $table->json('name');
            $table->string('flag', 16)->nullable(); // emoji or ISO code
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 96)->unique();
            $table->json('name');
            $table->string('publisher', 128)->nullable();
            $table->string('cover_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('hero_path')->nullable();
            $table->boolean('is_hot')->default(false)->index();
            $table->boolean('is_new')->default(false);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestampTz('first_listed_at')->nullable();
            $table->json('search_aliases')->nullable();
            $table->integer('offer_count_cached')->default(0);
            $table->integer('product_count_cached')->default(0);
            $table->timestampsTz();
        });

        Schema::create('game_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_type_id')->constrained()->restrictOnDelete();
            $table->string('slug', 96);
            $table->json('label_override')->nullable();      // "Coins", "MT", "V-Bucks"
            $table->json('attribute_schema')->nullable();    // filters & product/offer attributes
            $table->json('buyer_input_schema')->nullable();  // fields asked at checkout (M6)
            $table->json('allowed_delivery_methods')->nullable();
            $table->json('policy_override')->nullable();
            $table->json('notice')->nullable();              // "Important notice" per locale
            $table->integer('offer_count_cached')->default(0);
            $table->integer('product_count_cached')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['game_id', 'category_type_id']);
            $table->unique(['game_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('game_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 160);
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('image_path')->nullable();
            $table->json('attribute_values')->nullable(); // not 'attributes': that name collides with Eloquent's internal property
            $table->bigInteger('msrp_amount')->nullable();
            $table->char('msrp_currency', 3)->nullable();
            $table->bigInteger('lowest_price_amount_cached')->nullable(); // USD cents, from active offers (M5)
            $table->integer('offer_count_cached')->default(0);
            $table->string('status', 16)->default('draft')->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['game_category_id', 'slug']);
        });
    }

    public function down(): void
    {
        foreach (['products', 'game_categories', 'games', 'regions', 'platforms', 'category_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
