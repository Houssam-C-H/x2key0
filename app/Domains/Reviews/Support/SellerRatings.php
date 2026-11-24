<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Sellers\Models\SellerStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ratings from visible comments (spec Part 13): positive rate = positive / (positive + negative), lifetime and the
 * last 90 days (null until there is a positive or negative comment); the feedback score table (7 d / 30 d / all ×
 * positive / neutral / negative); sub-score averages for the "Transaction level".
 */
final class SellerRatings
{
    /** Writes the seller's positive rates and comment count to seller_stats. */
    public function refresh(int $sellerProfileId): void
    {
        $all = self::counts(OrderComment::query()->visible()->where('seller_profile_id', $sellerProfileId));
        $recent = self::counts(OrderComment::query()->visible()->where('seller_profile_id', $sellerProfileId)->where('created_at', '>=', now()->subDays(90)));

        SellerStats::query()->firstOrCreate(['seller_profile_id' => $sellerProfileId])->forceFill([
            'positive_rate_bps' => self::rate($all),
            'positive_rate_90d_bps' => self::rate($recent),
            'rating_count' => array_sum($all),
        ])->save();
    }

    /**
     * Feedback score table: rows 7 d / 30 d / all, columns positive / neutral / negative.
     *
     * @param  'seller'|'buyer'  $side
     * @return array{periods: array<string, array{positive: int, neutral: int, negative: int}>, positiveRateBps: int|null}
     */
    public static function scoreTable(string $side, int $id): array
    {
        $column = $side === 'seller' ? 'seller_profile_id' : 'buyer_id';
        $periods = [];
        foreach (['7d' => 7, '30d' => 30, 'all' => null] as $key => $days) {
            $q = OrderComment::query()->where($column, $id)->when($side === 'seller', fn ($q) => $q->visible());
            if ($days !== null) {
                $q->where('created_at', '>=', now()->subDays($days));
            }
            $periods[$key] = self::counts($q);
        }

        return ['periods' => $periods, 'positiveRateBps' => self::rate($periods['all'])];
    }

    /**
     * Average sub-scores (1–5, one decimal) of the seller's visible comments.
     *
     * @return array{delivery: float|null, service: float|null, description: float|null, overall: float|null}
     */
    public static function subScores(int $sellerProfileId): array
    {
        $row = OrderComment::query()->visible()->where('seller_profile_id', $sellerProfileId)
            ->selectRaw('AVG(score_delivery) as d, AVG(score_service) as s, AVG(score_description) as i')->toBase()->first();
        $avg = static fn (mixed $v): ?float => is_numeric($v) ? round((float) $v, 1) : null;
        $scores = ['delivery' => $avg($row->d ?? null), 'service' => $avg($row->s ?? null), 'description' => $avg($row->i ?? null)];
        $known = array_filter($scores, static fn (?float $v): bool => $v !== null);

        return [...$scores, 'overall' => $known === [] ? null : round(array_sum($known) / count($known), 1)];
    }

    /** "fauvuuvu" → "fau***uvu" (spec: buyer names masked on seller and public pages). */
    public static function maskBuyer(User $buyer): string
    {
        $name = $buyer->name;
        $len = mb_strlen($name);

        return $len <= 4 ? mb_substr($name, 0, 1).'***' : mb_substr($name, 0, 3).'***'.mb_substr($name, -min(3, $len - 3));
    }

    /**
     * @param  Builder<OrderComment>  $query
     * @return array{positive: int, neutral: int, negative: int}
     */
    private static function counts($query): array
    {
        $out = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach ($query->groupBy('rating')->select('rating', DB::raw('COUNT(*) as n'))->toBase()->get() as $row) {
            $rating = is_string($row->rating ?? null) ? $row->rating : '';
            if (isset($out[$rating])) {
                $out[$rating] = is_numeric($row->n ?? null) ? (int) $row->n : 0;
            }
        }

        return $out;
    }

    /**
     * @param  array{positive: int, neutral: int, negative: int}  $c
     */
    private static function rate(array $c): ?int
    {
        $rated = $c['positive'] + $c['negative'];

        return $rated === 0 ? null : intdiv($c['positive'] * 10000, $rated);
    }

    /** For tests and the recompute job: a period start. */
    public static function since(int $days): Carbon
    {
        return now()->subDays($days);
    }
}
