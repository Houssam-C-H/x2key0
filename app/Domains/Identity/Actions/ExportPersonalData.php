<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Personal data export (spec Part 23 "Privacy: data export", GDPR art. 15 / 20): one JSON document with what the
 * platform holds about the user — profile, preferences, sign-ins and devices, orders (as buyer), comments, chat
 * messages they wrote, tickets, coupons, points and money statements (journal lines on their own accounts), seller
 * store and affiliate details. Secrets are never included (passwords, 2FA, encrypted vault payloads, delivered keys,
 * payout account numbers). Every export is audited.
 */
final class ExportPersonalData
{
    public const int ROW_LIMIT = 5000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $id = $user->id;
        $rows = static fn (string $table, string $column, array $columns): array => self::rows($table, $column, $id, array_values(array_filter($columns, is_string(...))));

        $seller = DB::table('seller_profiles')->where('user_id', $id)->first(['id', 'store_name', 'slug', 'country', 'status', 'created_at']);
        $affiliate = DB::table('affiliates')->where('user_id', $id)->first(['id', 'code', 'status', 'clicks', 'signups', 'created_at']);

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'public_id' => $user->public_id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone' => $user->phone,
                'country' => $user->country,
                'locale' => $user->locale,
                'display_currency' => $user->display_currency,
                'timezone' => $user->timezone,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'status' => $user->status->value,
                'created_at' => $user->created_at->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'preferences' => DB::table('user_preferences')->where('user_id', $id)->first(['notification_mute', 'seller_promo_emails', 'bookmark_prompt_dismissed']),
            'sign_ins' => $rows('login_events', 'user_id', ['created_at', 'ip', 'user_agent', 'country', 'succeeded']),
            'devices' => $rows('user_devices', 'user_id', ['name', 'trusted_at', 'last_seen_at']),
            'sessions' => $rows('user_sessions', 'user_id', ['ip', 'user_agent', 'last_active_at', 'revoked_at']),
            'orders' => $rows('orders', 'buyer_id', ['number', 'status', 'currency', 'total_amount', 'paid_credits_amount', 'paid_psp_amount', 'created_at', 'completed_at', 'cancelled_at']),
            'comments' => $rows('order_comments', 'buyer_id', ['rating', 'score_delivery', 'score_service', 'score_description', 'body', 'seller_reply', 'created_at']),
            'chat_messages' => $rows('messages', 'sender_id', ['body', 'created_at']),
            'tickets' => $rows('tickets', 'user_id', ['topic', 'subject', 'status', 'created_at', 'closed_at']),
            'ticket_messages' => $rows('ticket_messages', 'author_id', ['body', 'created_at']),
            'coupons' => $rows('coupon_claims', 'user_id', ['status', 'source', 'claimed_at', 'expires_at']),
            'favorites' => $rows('favorites', 'user_id', ['favoritable_type', 'favoritable_id', 'created_at']),
            'money' => $this->postings($id),
            'points' => DB::table('points_postings')->join('points_accounts', 'points_accounts.id', '=', 'points_postings.account_id')
                ->join('points_journals', 'points_journals.id', '=', 'points_postings.journal_id')
                ->where('points_accounts.owner_user_id', $id)->orderBy('points_postings.id')->limit(self::ROW_LIMIT)
                ->get(['points_journals.type', 'points_postings.amount', 'points_postings.created_at'])->map(static fn (object $r): array => (array) $r)->all(),
            'seller_store' => $seller,
            'affiliate' => $affiliate,
            'withdrawals' => $rows('withdrawal_requests', 'user_id', ['amount', 'fee_amount', 'currency', 'status', 'payout_label', 'requested_at']),
        ];

        $this->audit->log('privacy.data_exported', $user, actor: $user);

        return $data;
    }

    /**
     * Journal lines on the user's own ledger accounts (credits, seller and affiliate balances).
     *
     * @return list<array<mixed>>
     */
    private function postings(int $userId): array
    {
        return array_values(DB::table('ledger_postings')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_accounts.owner_type', 'user')->where('ledger_accounts.owner_id', $userId)
            ->orderBy('ledger_postings.id')->limit(self::ROW_LIMIT)
            ->get(['ledger_accounts.code as account', 'ledger_journals.type', 'ledger_postings.amount', 'ledger_postings.currency', 'ledger_postings.created_at'])
            ->map(static fn (object $r): array => (array) $r)->all());
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<mixed>>
     */
    private static function rows(string $table, string $column, int $id, array $columns): array
    {
        return array_values(DB::table($table)->where($column, $id)->orderBy('id')->limit(self::ROW_LIMIT)->get($columns)
            ->map(static fn (object $r): array => (array) $r)->all());
    }
}
