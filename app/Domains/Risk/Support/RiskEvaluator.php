<?php

declare(strict_types=1);

namespace App\Domains\Risk\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Checkout\Support\Quote;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Risk\Enums\RiskDecision;
use App\Domains\Risk\Models\BlocklistEntry;
use App\Shared\Audit\AuditLog;
use App\Shared\Support\Settings;

/**
 * Risk rules v1 (spec 17.1), thresholds from Settings. Checkout rules run before an order exists
 * (block = refused, review = order goes to payment review once paid); capture rules run on the PSP result.
 * Rules that need data from later milestones (proxy/VPN lookup, card velocity across PSPs, affiliate
 * signals) arrive with them.
 */
final class RiskEvaluator
{
    /** Category types treated as high-risk goods for new buyers. */
    private const array SENSITIVE_TYPES = [
        CategoryTypeCode::GiftCards, CategoryTypeCode::EgiftCards, CategoryTypeCode::RetailGiftCards,
        CategoryTypeCode::GamingEcards, CategoryTypeCode::Accounts,
    ];

    public function __construct(private readonly Settings $settings) {}

    public function atCheckout(User $buyer, Offer $offer, Quote $quote, ?string $ip): RiskResult
    {
        $result = RiskResult::pass();
        $hour = now()->subHour();

        // Blocklist: e-mail domain or IP.
        $domain = mb_strtolower((string) substr(strrchr($buyer->email, '@') ?: '', 1));
        if (BlocklistEntry::hits('email_domain', $domain) || BlocklistEntry::hits('ip', $ip)) {
            $result = $result->with('blocklist', RiskDecision::Block, 100);
        }

        // Orders per buyer per hour (this one included).
        $recent = Order::query()->where('buyer_id', $buyer->id)->where('created_at', '>=', $hour)->count() + 1;
        if ($recent > $this->settings->int('risk_orders_per_hour_block')) {
            $result = $result->with('order_velocity', RiskDecision::Block, 60);
        } elseif ($recent > $this->settings->int('risk_orders_per_hour_review')) {
            $result = $result->with('order_velocity', RiskDecision::Review, 30);
        }

        // Failed payments from this IP in the last hour.
        if ($ip !== null) {
            $failed = Payment::query()
                ->where('status', PaymentStatus::Failed)
                ->where('updated_at', '>=', $hour)
                ->whereHas('order', fn ($q) => $q->where('ip', $ip))
                ->count();
            if ($failed > $this->settings->int('risk_failed_payments_per_ip_block')) {
                $result = $result->with('failed_payments_ip', RiskDecision::Block, 60);
            }
        }

        // New account (< 24 h) buying above the review amount.
        if ($buyer->created_at->gt(now()->subDay()) && $quote->total > $this->settings->int('risk_review_new_account_amount')) {
            $result = $result->with('new_account_amount', RiskDecision::Review, 30);
        }

        // Gift cards / accounts for a new buyer (young account without a completed order).
        $offer->loadMissing('gameCategory.type');
        if (in_array($offer->gameCategory->type->code, self::SENSITIVE_TYPES, true) && $this->isNewBuyer($buyer)) {
            $result = $result->with('sensitive_goods_new_buyer', RiskDecision::Review, 20);
        }

        // Credits spent right after a password or e-mail change (account takeover).
        if ($quote->credits > 0 && $this->credentialsChangedRecently($buyer)) {
            $result = $result->with('credits_after_credential_change', RiskDecision::Review, 40);
        }

        return $result;
    }

    /** Capture-time checks on top of the decision stored at checkout. */
    public function atCapture(Order $order, ?Payment $payment): RiskResult
    {
        $result = RiskResult::pass();

        if ($order->risk_decision === RiskDecision::Review) {
            $result = $result->with('checkout_review', RiskDecision::Review, 0);
        }

        if ($payment !== null && $payment->card_country !== null && $order->buyer_country !== null && $payment->card_country !== $order->buyer_country) {
            $result = $result->with('card_country_mismatch', RiskDecision::Pass, 20);
        }

        if ($order->risk_score + $result->score >= $this->settings->int('risk_review_score')) {
            $result = $result->with('score_threshold', RiskDecision::Review, 0);
        }

        return $result;
    }

    private function isNewBuyer(User $buyer): bool
    {
        return $buyer->created_at->gt(now()->subDays($this->settings->int('risk_new_buyer_days')))
            && ! Order::query()->where('buyer_id', $buyer->id)->where('status', OrderStatus::Completed)->exists();
    }

    private function credentialsChangedRecently(User $buyer): bool
    {
        $changes = AuditLog::query()
            ->where('subject_type', $buyer->getMorphClass())
            ->where('subject_id', $buyer->id)
            ->where('action', 'users.updated')
            ->where('created_at', '>=', now()->subDay())
            ->get(['after']);

        foreach ($changes as $change) {
            $after = $change->after;
            if (is_array($after) && (array_key_exists('password', $after) || array_key_exists('email', $after))) {
                return true;
            }
        }

        return false;
    }
}
