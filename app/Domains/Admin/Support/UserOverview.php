<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Points\Support\Points;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Violations\Models\Violation;
use App\Shared\Support\Translations;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything staff need about one account on a single page (docs/ADMIN_PLAN.md item 3): profile and access,
 * balances, sessions and devices, sign-ins, orders, tickets, disputes, violations and staff notes. Latest rows
 * only; each list links to its full screen. Never secrets (2FA, passwords, vault payloads, payout numbers).
 */
final class UserOverview
{
    public const int ROWS = 10;

    public function __construct(
        private readonly CreditsWallet $wallet,
        private readonly StaffNotes $notes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $user->loadMissing(['level', 'sellerProfile', 'roles']);
        $profile = $user->sellerProfile;
        $configured = config('x2key.settlement_currency');
        $currency = is_string($configured) ? $configured : 'USD';

        return [
            'profile' => [
                'Username' => $user->username,
                'Display name' => $user->name,
                'Public ID' => $user->public_id,
                'E-mail' => $user->email.($user->email_verified_at !== null ? ' ✓' : ' (not verified)'),
                'Phone' => $user->phone === null ? '—' : $user->phone.($user->phone_verified_at !== null ? ' ✓' : ' (not verified)'),
                'Country / language / currency' => ($user->country ?? '—').' · '.$user->locale.' · '.$user->display_currency,
                'Level' => $user->level !== null ? Translations::pick($user->level->name, 'en') : '—',
                'Two-step verification' => $user->two_factor_confirmed_at !== null ? 'on since '.Format::date($user->two_factor_confirmed_at) : 'off',
                'Staff roles' => $user->getRoleNames()->implode(', ') ?: '—',
                'Joined' => Format::date($user->created_at),
                'Last sign-in' => Format::date($user->last_login_at),
                'Last seen' => Format::date($user->last_seen_at),
            ],
            'status' => $user->status->value,
            'store' => $profile === null ? null : ['id' => $profile->id, 'name' => $profile->store_name, 'status' => Format::enum($profile->status)],
            'balances' => [
                'Credits' => Format::money($this->wallet->available($user, $currency), $currency),
                'X2K Score (points)' => number_format(Points::balance($user->id)),
            ],
            'counts' => [
                'orders' => Order::query()->where('buyer_id', $user->id)->count(),
                'tickets' => Ticket::query()->where('user_id', $user->id)->count(),
                'disputes' => $this->disputes($user)->count(),
                'violations' => $profile === null ? 0 : Violation::query()->where('seller_profile_id', $profile->id)->count(),
                'failedLogins30d' => LoginEvent::query()->where('user_id', $user->id)->where('succeeded', false)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'sessions' => UserSession::query()->where('user_id', $user->id)->latest('last_active_at')->limit(self::ROWS)->get()
                ->map(fn (UserSession $s): array => [
                    'id' => $s->id,
                    'ip' => $s->ip ?? '—',
                    'agent' => mb_strimwidth($s->user_agent ?? '—', 0, 70, '…'),
                    'lastActive' => Format::date($s->last_active_at),
                    'revoked' => $s->revoked_at !== null,
                ])->all(),
            'devices' => UserDevice::query()->where('user_id', $user->id)->latest('last_seen_at')->limit(self::ROWS)->get()
                ->map(fn (UserDevice $d): array => [
                    'name' => $d->name ?? 'Unnamed device',
                    'trusted' => $d->trusted_at !== null,
                    'firstSeen' => Format::date($d->created_at),
                    'lastSeen' => Format::date($d->last_seen_at),
                ])->all(),
            'logins' => LoginEvent::query()->where('user_id', $user->id)->latest('id')->limit(15)->get()
                ->map(fn (LoginEvent $e): array => [
                    'at' => Format::date($e->created_at),
                    'ok' => $e->succeeded,
                    'ip' => $e->ip ?? '—',
                    'agent' => mb_strimwidth($e->user_agent ?? '—', 0, 70, '…'),
                ])->all(),
            'orders' => Order::query()->where('buyer_id', $user->id)->latest('id')->limit(self::ROWS)->get()
                ->map(fn (Order $o): array => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'status' => Format::enum($o->status),
                    'total' => Format::money($o->total_amount, $o->currency),
                    'at' => Format::date($o->created_at),
                ])->all(),
            'tickets' => Ticket::query()->where('user_id', $user->id)->latest('id')->limit(self::ROWS)->get()
                ->map(fn (Ticket $t): array => [
                    'id' => $t->public_id,
                    'subject' => $t->subject,
                    'topic' => Format::enum($t->topic),
                    'status' => Format::enum($t->status),
                    'at' => Format::date($t->created_at),
                ])->all(),
            'disputes' => $this->disputes($user)->with('order')->latest('id')->limit(self::ROWS)->get()
                ->map(fn (Dispute $d): array => [
                    'id' => $d->public_id,
                    'order' => $d->order->number,
                    'reason' => Format::enum($d->reason),
                    'status' => Format::enum($d->status),
                    'at' => Format::date($d->created_at),
                ])->all(),
            'violations' => $profile === null ? [] : Violation::query()->with('rule')->where('seller_profile_id', $profile->id)->latest('id')->limit(self::ROWS)->get()
                ->map(fn (Violation $v): array => [
                    'id' => $v->public_id,
                    'rule' => Translations::pick($v->rule->name, 'en'),
                    'points' => $v->points,
                    'status' => Format::enum($v->status),
                    'at' => Format::date($v->created_at),
                ])->all(),
            'notes' => $this->notes->for('user', $user->id)->map(fn ($n): array => [
                'author' => $n->author->username,
                'at' => Format::date($n->created_at),
                'body' => $n->body,
            ])->all(),
        ];
    }

    /**
     * Disputes the user opened or that concern an order they bought.
     *
     * @return Builder<Dispute>
     */
    private function disputes(User $user): Builder
    {
        return Dispute::query()->where(fn (Builder $q) => $q
            ->where('opened_by_id', $user->id)
            ->orWhereIn('order_id', Order::query()->where('buyer_id', $user->id)->select('id')));
    }
}
