<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews (spec 5.11, Part 13): one comment per completed order, seller reply, moderation, community feature flag.
 * Seller levels gain the remaining recompute thresholds (spec 3.3: rating, dispute losses, violations) and seller
 * stats a 90-day positive rate. The comment points rule is seeded (spec 5.13 trigger `comment`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_profile_id')->constrained();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('offer_id')->nullable()->constrained();
            $table->string('rating', 8); // positive | neutral | negative
            $table->unsignedTinyInteger('score_delivery')->nullable();    // 1..5 Delivery Speed
            $table->unsignedTinyInteger('score_service')->nullable();     // 1..5 Service Quality
            $table->unsignedTinyInteger('score_description')->nullable(); // 1..5 Item Description
            $table->text('body')->nullable();
            $table->string('status', 8)->default('visible'); // visible | hidden
            $table->string('hidden_reason', 500)->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users');
            $table->text('seller_reply')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->boolean('featured_in_community')->default(false);
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();
            $table->index(['seller_profile_id', 'status', 'created_at']);
            $table->index(['buyer_id', 'created_at']);
            $table->index(['status', 'rating', 'created_at']);
        });

        Schema::table('seller_stats', function (Blueprint $table): void {
            $table->unsignedSmallInteger('positive_rate_90d_bps')->nullable()->after('positive_rate_bps');
        });

        Schema::table('seller_levels', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_positive_rate_bps')->default(0)->after('min_completion_rate_bps');
            $table->unsignedSmallInteger('max_dispute_loss_bps')->nullable()->after('min_positive_rate_bps');
            $table->unsignedSmallInteger('max_violation_points')->nullable()->after('max_dispute_loss_bps');
        });

        // Thresholds for the levels already seeded (SellerLevelSeeder keeps the same values).
        foreach ([
            'silver' => [9000, 300, 10],
            'gold' => [9500, 200, 6],
            'platinum' => [9700, 100, 4],
            'diamond' => [9800, 50, 2],
        ] as $code => [$positive, $disputes, $violations]) {
            DB::table('seller_levels')->where('code', $code)->update([
                'min_positive_rate_bps' => $positive,
                'max_dispute_loss_bps' => $disputes,
                'max_violation_points' => $violations,
            ]);
        }

        if (DB::table('points_rules')->where('code', 'comment')->doesntExist()) {
            DB::table('points_rules')->insert([
                'code' => 'comment',
                'trigger' => 'comment',
                'amount_per_usd' => null,
                'fixed' => 10,
                'multiplier_by_level' => false,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('points_rules')->where('code', 'comment')->delete();
        Schema::table('seller_levels', function (Blueprint $table): void {
            $table->dropColumn(['min_positive_rate_bps', 'max_dispute_loss_bps', 'max_violation_points']);
        });
        Schema::table('seller_stats', function (Blueprint $table): void {
            $table->dropColumn('positive_rate_90d_bps');
        });
        Schema::dropIfExists('order_comments');
    }
};
