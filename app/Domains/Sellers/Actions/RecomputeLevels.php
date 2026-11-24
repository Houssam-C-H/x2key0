<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserLevel;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Reviews\Support\SellerRatings;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStats;
use Illuminate\Support\Carbon;

/**
 * Levels (spec 3.3), computed by job, staff override kept (`level_locked`, audited):
 *
 * - seller stats from orders and comments: completion rate = completed / (completed + cancelled by the seller),
 *   positive rates, 30-day sales (seller-side), average paid → delivered minutes over 90 days;
 * - seller level = the highest level whose thresholds all hold: orders completed, completion rate, positive rate
 *   (no rating yet counts as 0), dispute-loss rate (lost / completed), violation points;
 * - user (buyer) level = the highest level whose `min_spend_amount` is reached by the lifetime completed spend
 *   (total paid minus refunds), also right after each completed order.
 */
final class RecomputeLevels
{
    public function __construct(private readonly SellerRatings $ratings) {}

    /**
     * @return array{sellers: int, changed: int, users: int}
     */
    public function all(): array
    {
        $sellers = 0;
        $changed = 0;
        SellerProfile::query()->orderBy('id')->chunkById(200, function ($profiles) use (&$sellers, &$changed): void {
            foreach ($profiles as $profile) {
                $sellers++;
                $changed += $this->seller($profile) ? 1 : 0;
            }
        });

        $users = 0;
        Order::query()->where('status', OrderStatus::Completed)->distinct()->orderBy('buyer_id')->pluck('buyer_id')
            ->each(function (mixed $id) use (&$users): void {
                $user = is_numeric($id) ? User::query()->find((int) $id) : null;
                if ($user !== null && $this->user($user)) {
                    $users++;
                }
            });

        return ['sellers' => $sellers, 'changed' => $changed, 'users' => $users];
    }

    /** Refreshes the stats and the level of one seller; true when the level changed. */
    public function seller(SellerProfile $profile): bool
    {
        $stats = $this->stats($profile);
        if ($profile->level_locked) {
            return false;
        }

        $level = self::levelFor($stats);
        if ($level === null || $level->id === $profile->seller_level_id) {
            return false;
        }

        $up = $profile->seller_level_id === null || $level->sort > (SellerLevel::query()->whereKey($profile->seller_level_id)->value('sort') ?? -1);
        $profile->forceFill(['seller_level_id' => $level->id])->save(); // audited by Auditable
        Notifier::notify($profile->user_id, $up ? 'seller_level_up' : 'seller_level_down', 'merchant', [
            'level' => $level->translate('name', 'en'),
            'href' => '/seller/store',
        ]);

        return true;
    }

    /** Buyer level from lifetime completed spend; true when it changed. */
    public function user(User $user): bool
    {
        $row = Order::query()->where('buyer_id', $user->id)->where('status', OrderStatus::Completed)
            ->selectRaw('COALESCE(SUM(total_amount - refunded_credits_amount - refunded_psp_amount), 0) as spend')->toBase()->first();
        $value = $row === null ? null : ($row->spend ?? null);
        $spend = is_numeric($value) ? (int) $value : 0;

        $level = UserLevel::query()->where('min_spend_amount', '<=', $spend)->orderByDesc('min_spend_amount')->orderByDesc('sort')->first();
        if ($level === null || $level->id === $user->user_level_id) {
            return false;
        }

        $user->forceFill(['user_level_id' => $level->id])->save();

        return true;
    }

    public static function levelFor(SellerStats $stats): ?SellerLevel
    {
        $completed = $stats->orders_completed;
        $completion = $stats->completion_rate_bps ?? 0;
        $positive = $stats->positive_rate_bps ?? 0;
        $disputeLoss = $completed === 0 ? 0 : intdiv($stats->disputes_lost * 10000, $completed);

        foreach (SellerLevel::query()->orderByDesc('sort')->get() as $level) {
            if ($completed >= $level->min_orders_completed
                && ($level->min_completion_rate_bps === 0 || $completion >= $level->min_completion_rate_bps)
                && ($level->min_positive_rate_bps === 0 || $positive >= $level->min_positive_rate_bps)
                && ($level->max_dispute_loss_bps === null || $disputeLoss <= $level->max_dispute_loss_bps)
                && ($level->max_violation_points === null || $stats->violation_points <= $level->max_violation_points)) {
                return $level;
            }
        }

        return null;
    }

    private function stats(SellerProfile $profile): SellerStats
    {
        $this->ratings->refresh($profile->id);
        $stats = SellerStats::query()->firstOrCreate(['seller_profile_id' => $profile->id]);

        $orders = Order::query()->where('seller_profile_id', $profile->id);
        $completed = (clone $orders)->where('status', OrderStatus::Completed)->count();
        $decided = $completed + $stats->orders_cancelled_by_seller;

        $sales = (clone $orders)->where('status', OrderStatus::Completed)->where('completed_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(SUM(seller_net_amount + commission_amount), 0) as total')->toBase()->first();

        $minutes = [];
        foreach ((clone $orders)->whereNotNull('paid_at')->whereNotNull('delivered_at')->where('delivered_at', '>=', now()->subDays(90))
            ->orderByDesc('id')->limit(500)->get(['paid_at', 'delivered_at']) as $o) {
            if ($o->paid_at instanceof Carbon && $o->delivered_at instanceof Carbon) {
                $minutes[] = max(0, (int) $o->paid_at->diffInMinutes($o->delivered_at));
            }
        }

        $stats->forceFill([
            'orders_completed' => $completed,
            'completion_rate_bps' => $decided === 0 ? null : intdiv($completed * 10000, $decided),
            'sales_30d_amount' => is_numeric($total = $sales === null ? null : ($sales->total ?? null)) ? (int) $total : 0,
            'avg_delivery_minutes' => $minutes === [] ? null : intdiv(array_sum($minutes), count($minutes)),
        ])->save();

        return $stats->refresh();
    }
}
