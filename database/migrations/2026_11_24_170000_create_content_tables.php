<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home CMS, news, help center, footer pages and SEO rows (spec 5.17, 1.3, Part 20). Trending scores on games and
 * game×categories are written by `home:refresh-trending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('title')->nullable(); // section title override (else the default string)
            $table->json('config')->nullable(); // e.g. {"limit": 10}
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('home_module_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('home_module_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 16); // game_category | game | product | comment | article
            $table->unsignedBigInteger('item_id');
            $table->boolean('pinned')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['home_module_id', 'item_type', 'item_id']);
        });

        Schema::create('news_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body');
            $table->string('cover_path', 500)->nullable();
            $table->foreignId('game_id')->nullable()->constrained();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->string('status', 10)->default('draft'); // draft | published
            $table->timestampsTz();
            $table->index(['status', 'published_at']);
        });

        Schema::create('help_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('section', 20); // how_to_buy | how_to_sell | how_to_cancel | payments | account | faq
            $table->json('title');
            $table->json('body');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestampsTz();
            $table->index(['section', 'sort']);
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->json('title');
            $table->json('body');
            $table->string('footer_column', 10)->nullable(); // company | support | legal | policies
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestampsTz();
        });

        Schema::create('seo_link_rows', function (Blueprint $table): void {
            $table->id();
            $table->json('label');
            $table->string('url', 500);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::table('game_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('trend_score')->default(0);
            $table->index('trend_score');
        });
        Schema::table('games', function (Blueprint $table): void {
            $table->unsignedBigInteger('hot_score')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('hot_score'));
        Schema::table('game_categories', function (Blueprint $table): void {
            $table->dropIndex(['trend_score']);
            $table->dropColumn('trend_score');
        });
        foreach (['seo_link_rows', 'pages', 'help_articles', 'news_articles', 'home_module_items', 'home_modules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
