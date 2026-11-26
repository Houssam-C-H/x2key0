<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use InvalidArgumentException;

/**
 * What the Platform settings screen knows about each Part 27 policy key: its tab, unit, label and limits.
 * A test keeps this list in step with config('x2key.policy'), so a new key cannot be added without a label.
 *
 * Money settings (the Money tab, and amounts / rates on any tab) need a second admin's approval.
 */
final class PolicyCatalog
{
    public const array GROUPS = [
        'orders' => 'Orders',
        'money' => 'Money',
        'risk' => 'Risk',
        'sellers' => 'Sellers',
        'marketing' => 'Marketing',
    ];

    /** Units: what the stored integer means. `money` = USD cents, `bps` = 1/100 %. */
    public const array UNITS = [
        'seconds' => 'seconds', 'minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days', 'months' => 'months',
        'count' => '', 'money' => 'USD', 'bps' => '%', 'millicents' => 'USD', 'mb' => 'MB',
    ];

    /**
     * key => [group, kind, label, max]; kind = a unit above, `bool`, `choice:a|b`, `days_map` or `?months` (empty = never).
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3?: int}>
     */
    private const array DEFINITIONS = [
        // Orders
        'payment_window_minutes' => ['orders', 'minutes', 'Time to pay before an order expires', 1440],
        'accept_due_minutes' => ['orders', 'minutes', 'Time for the seller to accept', 1440],
        'default_delivery_time_minutes' => ['orders', 'minutes', 'Default delivery time of an offer', 10080],
        'max_delivery_time_minutes' => ['orders', 'minutes', 'Longest delivery time a seller may set', 43200],
        'auto_cancel_after_sla_hours' => ['orders', 'hours', 'Auto-cancel after the delivery time is missed by', 720],
        'confirmation_window_hours' => ['orders', 'hours', 'Auto-confirm after delivery', 720],
        'confirmation_window_hours_services' => ['orders', 'hours', 'Auto-confirm after delivery (services)', 2160],
        'post_completion_dispute_days' => ['orders', 'days', 'Disputes allowed after completion for', 180],
        'dispute_response_hours' => ['orders', 'hours', 'Time to answer a dispute', 720],
        'not_received_contact_hours' => ['orders', 'hours', '"Not received": seller contact window', 720],
        'not_received_report_hours' => ['orders', 'hours', '"Not received": report window', 720],
        'reveal_requires_reauth_minutes' => ['orders', 'minutes', 'Re-enter password to reveal after', 1440],
        'payment_sync_after_minutes' => ['orders', 'minutes', 'Ask the provider about a pending payment after', 1440],
        'refund_default_destination' => ['orders', 'choice:original_sources|credits', 'Where refunds go by default'],
        'comment_window_days' => ['orders', 'days', 'Buyers can comment on an order for', 365],
        'ticket_autoclose_hours' => ['orders', 'hours', 'Close answered tickets after', 720],
        'ticket_reopen_days' => ['orders', 'days', 'Closed tickets can be reopened for', 90],
        'ticket_sla_hours_high' => ['orders', 'hours', 'First reply target, high priority', 168],
        'ticket_sla_hours_normal' => ['orders', 'hours', 'First reply target, normal priority', 168],
        'ticket_sla_hours_low' => ['orders', 'hours', 'First reply target, low priority', 336],
        'account_deletion_cooloff_days' => ['orders', 'days', 'Account deletion cool-off', 90],
        'browsing_history_limit' => ['orders', 'count', 'Browsing history entries kept per user', 500],
        'online_threshold_seconds' => ['orders', 'seconds', 'Shown "online" for', 3600],

        // Money
        'commission_bps' => ['money', 'bps', 'Seller commission', 5000],
        'buyer_fee_bps' => ['money', 'bps', 'Buyer service fee', 5000],
        'buyer_fee_min_amount' => ['money', 'money', 'Minimum buyer service fee', 100000],
        'settlement_hold_days' => ['money', 'days_map', 'Seller funds held after completion, per category type', 180],
        'account_recovery_protection_days' => ['money', 'days', 'Accounts: recovery protection', 365],
        'new_seller_extra_hold_days' => ['money', 'days', 'New sellers: extra hold', 180],
        'rolling_reserve_bps_new_seller' => ['money', 'bps', 'New sellers: rolling reserve', 5000],
        'reserve_release_days' => ['money', 'days', 'Rolling reserve released after', 365],
        'insurance_installments' => ['money', 'count', 'Hold released in instalments', 12],
        'credits_topup_enabled' => ['money', 'bool', 'Buyers can top up credits'],
        'withdrawal_min_amount' => ['money', 'money', 'Minimum withdrawal', 10000000],
        'withdrawal_fee_amount' => ['money', 'money', 'Withdrawal fee', 100000],
        'manual_adjustment_dual_approval_amount' => ['money', 'money', 'Ledger adjustments need a second approver from', 100000000],

        // Risk
        'max_order_amount_new_buyer' => ['risk', 'money', 'Largest order for a new buyer', 100000000],
        'risk_review_new_account_amount' => ['risk', 'money', 'Review new-account orders from', 100000000],
        'risk_orders_per_hour_review' => ['risk', 'count', 'Orders per hour before review', 1000],
        'risk_orders_per_hour_block' => ['risk', 'count', 'Orders per hour before block', 1000],
        'risk_failed_payments_per_ip_block' => ['risk', 'count', 'Failed payments per IP before block', 1000],
        'risk_new_buyer_days' => ['risk', 'days', 'A buyer counts as new for', 365],
        'risk_review_score' => ['risk', 'count', 'Risk score that sends an order to review', 100],
        'violation_appeal_days' => ['risk', 'days', 'Violation appeal window', 90],
        'violation_points_ttl_days' => ['risk', 'days', 'Violation points expire after', 730],
        'violation_points_store_suspend' => ['risk', 'count', 'Violation points that suspend a store', 1000],
        'violation_points_ban' => ['risk', 'count', 'Violation points that ban a store', 1000],

        // Sellers
        'new_seller_order_threshold' => ['sellers', 'count', 'A seller stops being "new" after orders', 10000],
        'low_stock_default_threshold' => ['sellers', 'count', 'Default low-stock alert', 10000],
        'listing_duration_days' => ['sellers', 'days', 'Offer listing duration', 365],
        'offer_expire_soon_hours' => ['sellers', 'hours', '"Expires soon" warning', 720],
        'storage_import_max_items' => ['sellers', 'count', 'Storage items per import', 100000],
        'featured_offers_max' => ['sellers', 'count', 'Featured offers per store', 50],
        'store_video_max_mb' => ['sellers', 'mb', 'Store video size limit', 500],

        // Marketing (points, affiliate, coupons, e-mails)
        'points_per_usd' => ['marketing', 'count', 'X2K Score points per USD spent', 10000],
        'points_expiry_months' => ['marketing', '?months', 'Points expire after (empty = never)', 120],
        'points_redeem_to_credits_enabled' => ['marketing', 'bool', 'Points can be turned into credits'],
        'points_redeem_min' => ['marketing', 'count', 'Minimum points per redemption', 100000000],
        'points_redeem_per_day' => ['marketing', 'count', 'Redemptions per day', 100],
        'affiliate_commission_bps' => ['marketing', 'bps', 'Affiliate commission (share of the fee)', 10000],
        'affiliate_level2_bps' => ['marketing', 'bps', 'Affiliate second-level commission', 10000],
        'affiliate_attribution_days' => ['marketing', 'days', 'Referral cookie lasts', 365],
        'affiliate_transfer_min' => ['marketing', 'money', 'Minimum affiliate transfer to credits', 10000000],
        'affiliate_hold_extra_days' => ['marketing', 'days', 'Commission held beyond the order hold', 180],
        'coupon_max_stack_platform' => ['marketing', 'count', 'Platform coupons per order', 10],
        'coupon_max_stack_store' => ['marketing', 'count', 'Store coupons per order', 10],
        'marketing_email_unit_price_millicents' => ['marketing', 'millicents', 'Price per marketing e-mail', 100000],
        'marketing_email_daily_cap' => ['marketing', 'count', 'Marketing e-mails per store per day', 1000000],
        'marketing_email_monthly_cap' => ['marketing', 'count', 'Marketing e-mails per store per month', 10000000],
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3?: int}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @return list<string>
     */
    public static function keysIn(string $group): array
    {
        return array_keys(array_filter(self::DEFINITIONS, fn (array $d): bool => $d[0] === $group));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3?: int}
     */
    public static function get(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    public static function kind(string $key): string
    {
        return self::get($key)[1];
    }

    public static function label(string $key): string
    {
        return self::get($key)[2];
    }

    public static function group(string $key): string
    {
        return self::get($key)[0];
    }

    /** Money settings wait for a second admin. */
    public static function needsSecondApproval(string $key): bool
    {
        return self::group($key) === 'money' || in_array(self::kind($key), ['money', 'bps', 'millicents'], true);
    }

    /**
     * Checks and normalises a value typed on the screen. Throws with a message for the staff member.
     */
    public static function normalise(string $key, mixed $value): mixed
    {
        $kind = self::kind($key);
        $max = self::get($key)[3] ?? PHP_INT_MAX;

        if ($kind === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        if (str_starts_with($kind, 'choice:')) {
            $options = explode('|', substr($kind, 7));
            if (! is_string($value) || ! in_array($value, $options, true)) {
                throw new InvalidArgumentException(self::label($key).': choose one of '.implode(', ', $options).'.');
            }

            return $value;
        }

        if ($kind === 'days_map') {
            /** @var array<string, mixed> $defaults */
            $defaults = config("x2key.policy.{$key}", []);
            if (! is_array($value)) {
                throw new InvalidArgumentException(self::label($key).': a value per category type is required.');
            }
            $out = [];
            foreach (array_keys($defaults) as $type) {
                $out[$type] = self::integer($key.' ('.$type.')', $value[$type] ?? null, $max);
            }

            return $out;
        }

        if ($kind === '?months' && ($value === null || $value === '')) {
            return null;
        }

        return self::integer(self::label($key), $value, $max);
    }

    /**
     * Shows a stored value in its unit, e.g. 800 bps → "8.00 %", 1000 cents → "USD 10.00".
     */
    public static function display(string $key, mixed $value): string
    {
        $kind = self::kind($key);

        return match (true) {
            $value === null => 'never',
            $kind === 'bool' => $value ? 'on' : 'off',
            $kind === 'days_map' && is_array($value) => collect($value)->map(fn (mixed $v, string $t): string => $t.' '.(is_scalar($v) ? (string) $v : '?'))->implode(', ').' days',
            ! is_numeric($value) => is_scalar($value) ? (string) $value : '?',
            $kind === 'money' => 'USD '.number_format((int) $value / 100, 2),
            $kind === 'millicents' => 'USD '.number_format((int) $value / 100000, 5),
            $kind === 'bps' => number_format((int) $value / 100, 2).' %',
            default => trim($value.' '.(self::UNITS[ltrim($kind, '?')] ?? '')),
        };
    }

    /** Decimal places typed on the screen for the kinds stored as scaled integers. */
    public static function decimals(string $key): int
    {
        return match (self::kind($key)) {
            'money', 'bps' => 2,
            'millicents' => 5,
            default => 0,
        };
    }

    /**
     * Stored value → what the form shows (cents → "10.00", bps → "8.00"). Pure string maths, no floats.
     */
    public static function toInput(string $key, mixed $value): mixed
    {
        $places = self::decimals($key);
        if ($places === 0 || ! is_int($value)) {
            return $value;
        }

        $digits = str_pad((string) $value, $places + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }

    /**
     * Form value → stored value ("10.5" → 1050 cents). Anything that is not a plain decimal is passed on unchanged
     * so normalise() rejects it with the right message.
     */
    public static function fromInput(string $key, mixed $value): mixed
    {
        $places = self::decimals($key);
        if ($places === 0 || ! is_scalar($value)) {
            return $value;
        }

        $text = trim((string) $value);
        if (preg_match('/^(\d+)(?:\.(\d{0,'.$places.'}))?$/', $text, $m) !== 1) {
            return $text;
        }

        return (int) ltrim($m[1].str_pad($m[2] ?? '', $places, '0'), '0');
    }

    private static function integer(string $label, mixed $value, int $max): int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (! is_int($value) || $value < 0 || $value > $max) {
            throw new InvalidArgumentException("{$label}: a whole number from 0 to {$max} is required.");
        }

        return $value;
    }
}
