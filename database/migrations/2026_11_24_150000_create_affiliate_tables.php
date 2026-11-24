<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate Program (spec 5.15, Part 15): affiliates (two levels through `parent_affiliate_id`), promotional links,
 * referrals with an attribution window, per-order commissions (held, released, reversed), video records and creative
 * assets. The Alliance Bill is read from the ledger, so it has no table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('code', 16)->unique();
            $table->foreignId('parent_affiliate_id')->nullable()->constrained('affiliates');
            $table->string('status', 10)->default('active'); // pending | active | suspended
            $table->unsignedSmallInteger('rank')->default(1);
            $table->unsignedSmallInteger('commission_bps')->nullable(); // null = setting `affiliate_commission_bps`
            $table->unsignedSmallInteger('level2_bps')->nullable();     // null = setting `affiliate_level2_bps`
            $table->unsignedSmallInteger('attribution_days')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('signups')->default(0);
            $table->timestampsTz();
        });

        Schema::create('affiliate_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 16)->unique();
            $table->string('target_path', 500);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('signups')->default(0);
            $table->timestampsTz();
        });

        Schema::create('affiliate_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained();
            $table->foreignId('referred_user_id')->unique()->constrained('users');
            $table->foreignId('affiliate_link_id')->nullable()->constrained('affiliate_links')->nullOnDelete();
            $table->timestampTz('first_click_at')->nullable();
            $table->timestampTz('signed_up_at');
            $table->timestampTz('attribution_expires_at');
            $table->index(['affiliate_id', 'signed_up_at']);
        });

        Schema::create('affiliate_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->unsignedTinyInteger('level'); // 1 direct · 2 the inviter's inviter
            $table->unsignedBigInteger('base_amount');
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('reversed_amount')->default(0);
            $table->char('currency', 3);
            $table->string('status', 10); // pending | approved | reversed
            $table->timestampTz('release_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->unique(['affiliate_id', 'order_id']);
            $table->index(['status', 'release_at']);
        });

        Schema::create('affiliate_video_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained();
            $table->string('platform', 10); // youtube | tiktok | twitch | other
            $table->string('url', 500);
            $table->unsignedInteger('views_reported')->default(0);
            $table->string('status', 10)->default('submitted'); // submitted | approved | rejected
            $table->unsignedBigInteger('reward_amount')->default(0);
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['affiliate_id', 'url']);
        });

        Schema::create('creative_assets', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->string('type', 10); // banner | logo | video | copy
            $table->string('theme', 40)->default('universal');
            $table->string('size', 20)->nullable(); // e.g. 728x90
            $table->string('locale', 5)->nullable(); // null = every language
            $table->string('file_path', 500)->nullable();
            $table->text('copy_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE users ADD CONSTRAINT users_referred_by_affiliate_fk FOREIGN KEY (referred_by_affiliate_id) REFERENCES affiliates (id);
                ALTER TABLE orders ADD CONSTRAINT orders_affiliate_fk FOREIGN KEY (affiliate_id) REFERENCES affiliates (id);
                ALTER TABLE affiliate_commissions ADD CONSTRAINT affiliate_commissions_reversal_ok CHECK (reversed_amount <= commission_amount);
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_referred_by_affiliate_fk; ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_affiliate_fk;');
        }
        foreach (['creative_assets', 'affiliate_video_records', 'affiliate_commissions', 'affiliate_referrals', 'affiliate_links', 'affiliates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
