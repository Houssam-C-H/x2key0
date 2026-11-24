<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Mail\MarketingMail;
use App\Domains\Campaigns\Models\MarketingEmail;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Paid marketing e-mail (spec 14.3): template type → the seller's live offers + optional private store coupon →
 * past buyers (completed orders in a date range, promo e-mails allowed) → preview → send. Cost = recipients ×
 * `marketing_email_unit_price_millicents`, rounded up to the cent; caps per seller `marketing_email_daily_cap`
 * and `marketing_email_monthly_cap`. Nothing is charged now: SettleMarketingEmails bills it the next day.
 */
final class SendMarketingEmail
{
    public const int MAX_OFFERS = 6;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Past buyers who allow seller promotions (user_preferences.seller_promo_emails, default yes).
     *
     * @return list<int> user ids
     */
    public function recipients(SellerProfile $seller, CarbonInterface $from, CarbonInterface $to): array
    {
        $ids = Order::query()
            ->where('seller_profile_id', $seller->id)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('completed_at', [$from, $to])
            ->where('buyer_id', '!=', $seller->user_id)
            ->whereNotExists(static function (QueryBuilder $q): void {
                $q->selectRaw('1')->from('user_preferences')->whereColumn('user_preferences.user_id', 'orders.buyer_id')->where('seller_promo_emails', false);
            })
            ->distinct()->orderBy('buyer_id')->pluck('buyer_id');

        return array_values($ids->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }

    /**
     * @return array{count: int, unitMillicents: int, total: int, dailyLeft: int, monthlyLeft: int}
     */
    public function preview(SellerProfile $seller, CarbonInterface $from, CarbonInterface $to): array
    {
        $count = count($this->recipients($seller, $from, $to));
        $unit = $this->settings->int('marketing_email_unit_price_millicents');

        return [
            'count' => $count,
            'unitMillicents' => $unit,
            'total' => self::cost($count, $unit),
            'dailyLeft' => max(0, $this->settings->int('marketing_email_daily_cap') - $this->sent($seller, now()->startOfDay())),
            'monthlyLeft' => max(0, $this->settings->int('marketing_email_monthly_cap') - $this->sent($seller, now()->startOfMonth())),
        ];
    }

    /**
     * @param  list<string>  $offerPublicIds
     */
    public function handle(SellerProfile $seller, string $type, array $offerPublicIds, ?string $couponPublicId, CarbonInterface $from, CarbonInterface $to): MarketingEmail
    {
        if (! $seller->status->isPublic()) {
            throw CouponException::because('marketing.error.store');
        }
        if (! in_array($type, MarketingEmail::TYPES, true)) {
            throw CouponException::because('marketing.error.type');
        }

        $offerPublicIds = array_values(array_unique($offerPublicIds));
        $offers = Offer::query()->live()->where('offers.seller_profile_id', $seller->id)->whereIn('offers.public_id', $offerPublicIds)->get();
        if ($offers->isEmpty() || $offers->count() !== count($offerPublicIds) || $offers->count() > self::MAX_OFFERS) {
            throw CouponException::because('marketing.error.offers', ['max' => self::MAX_OFFERS]);
        }

        $coupon = null;
        if ($couponPublicId !== null) {
            $coupon = Coupon::query()->live()->where('public_id', $couponPublicId)->where('issuer', Coupon::SELLER)
                ->where('seller_profile_id', $seller->id)->where('is_public', false)->first();
            if ($coupon === null) {
                throw CouponException::because('marketing.error.coupon');
            }
        }

        return DB::transaction(function () use ($seller, $type, $offers, $coupon, $from, $to): MarketingEmail {
            // Serialise sends per seller so the caps hold under concurrent requests.
            SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->first();

            $recipients = $this->recipients($seller, $from, $to);
            $count = count($recipients);
            if ($count === 0) {
                throw CouponException::because('marketing.error.no_recipients');
            }
            if ($this->sent($seller, now()->startOfDay()) + $count > $this->settings->int('marketing_email_daily_cap')
                || $this->sent($seller, now()->startOfMonth()) + $count > $this->settings->int('marketing_email_monthly_cap')) {
                throw CouponException::because('marketing.error.cap');
            }

            $unit = $this->settings->int('marketing_email_unit_price_millicents');
            $email = new MarketingEmail;
            $email->forceFill([
                'seller_profile_id' => $seller->id,
                'type' => $type,
                'offer_ids' => array_values($offers->map(static fn (Offer $o): int => $o->id)->all()),
                'coupon_id' => $coupon?->id,
                'recipient_count' => $count,
                'unit_price_millicents' => $unit,
                'total_amount' => self::cost($count, $unit),
                'status' => 'unsettled',
                'sent_at' => now(),
            ])->save();

            $email = $email->refresh();
            DB::afterCommit(function () use ($recipients, $email): void {
                foreach (User::query()->whereIn('id', $recipients)->get(['id', 'email', 'name', 'locale']) as $user) {
                    Mail::to($user->email)->queue(new MarketingMail($email->id, $user->id));
                }
            });

            return $email;
        });
    }

    /** Recipients × unit price in millicents, rounded up to whole cents. */
    public static function cost(int $count, int $unitMillicents): int
    {
        return intdiv($count * $unitMillicents + 999, 1000);
    }

    private function sent(SellerProfile $seller, Carbon $since): int
    {
        $sum = MarketingEmail::query()->where('seller_profile_id', $seller->id)->where('sent_at', '>=', $since)->sum('recipient_count');

        return (int) $sum;
    }
}
