<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Exceptions\AccountDeletionException;
use App\Domains\Identity\Models\AccountDeletionRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Identity\Notifications\AccountDeletionCode;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Account deletion (spec 6.8, Settings › Delete Account):
 *
 * - request — two different verification factors (e-mail code, Privacy Password, authenticator); blockers are
 *   money on any balance, open orders (as buyer or seller), open disputes, open withdrawals → `blocked` with the
 *   list; otherwise `scheduled` after `account_deletion_cooloff_days` (14). The user keeps access and can cancel;
 * - process (`accounts:process-deletions`, daily) — re-checks blockers, then anonymises the account (name, e-mail,
 *   username, phone, password, 2FA), revokes every session, closes the store; orders, journals and audit stay.
 */
final class DeleteAccount
{
    public const array FACTORS = ['email', 'privacy', 'authenticator'];

    private const int CODE_MINUTES = 10;

    /** Balances that must be zero before an account can go. */
    private const array BALANCES = [
        AccountCode::BuyerCredits, AccountCode::SellerPending, AccountCode::SellerAvailable, AccountCode::SellerReserve,
        AccountCode::SellerFrozen, AccountCode::SellerDeposit, AccountCode::SellerInsuredPending,
        AccountCode::AffiliatePending, AccountCode::AffiliateAvailable, AccountCode::PayoutInTransit,
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly SellerProfileStateMachine $sellers,
    ) {}

    /** Sends a 6-digit code by e-mail (factor `email`); valid 10 minutes. */
    public function sendEmailCode(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        Cache::put(self::codeKey($user), Hash::make($code), now()->addMinutes(self::CODE_MINUTES));
        $user->notify(new AccountDeletionCode($code, self::CODE_MINUTES));

        return $code;
    }

    /**
     * @param  array<string, string>  $proofs  factor => code / password
     */
    public function request(User $user, array $proofs, ?string $reason): AccountDeletionRequest
    {
        if ($this->open($user) !== null) {
            throw AccountDeletionException::because('account.delete.error.pending');
        }

        $used = $this->verify($user, $proofs);
        $blockers = $this->blockers($user);

        return DB::transaction(function () use ($user, $used, $blockers, $reason): AccountDeletionRequest {
            $request = new AccountDeletionRequest;
            $request->forceFill([
                'user_id' => $user->id,
                'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
                'status' => $blockers === [] ? AccountDeletionRequest::SCHEDULED : AccountDeletionRequest::BLOCKED,
                'blockers' => $blockers === [] ? null : $blockers,
                'factors' => $used,
                'scheduled_for' => $blockers === [] ? now()->addDays($this->settings->int('account_deletion_cooloff_days')) : null,
            ])->save();
            $this->audit->log('account.deletion_requested', $user, after: ['status' => $request->status, 'blockers' => $blockers], actor: $user);

            return $request->refresh();
        });
    }

    public function cancel(User $user): void
    {
        $open = $this->open($user);
        if ($open === null) {
            return;
        }

        $open->forceFill(['status' => AccountDeletionRequest::CANCELLED, 'cancelled_at' => now()])->save();
        $this->audit->log('account.deletion_cancelled', $user, actor: $user);
    }

    /** The scheduled (or blocked) request still waiting, if any. */
    public function open(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', AccountDeletionRequest::SCHEDULED)->latest('id')->first();
    }

    /**
     * Daily job: completes requests whose cool-off ended; a request that became blocked meanwhile is marked so.
     *
     * @return array{completed: int, blocked: int}
     */
    public function processDue(): array
    {
        $done = 0;
        $blocked = 0;

        AccountDeletionRequest::query()->where('status', AccountDeletionRequest::SCHEDULED)->where('scheduled_for', '<=', now())->with('user')->orderBy('id')
            ->chunkById(100, function ($requests) use (&$done, &$blocked): void {
                foreach ($requests as $request) {
                    $blockers = $this->blockers($request->user);
                    if ($blockers !== []) {
                        $request->forceFill(['status' => AccountDeletionRequest::BLOCKED, 'blockers' => $blockers])->save();
                        $blocked++;

                        continue;
                    }
                    $this->anonymise($request);
                    $done++;
                }
            });

        return ['completed' => $done, 'blocked' => $blocked];
    }

    /**
     * What stops the deletion now (translation keys).
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach (self::BALANCES as $code) {
            if (Accounts::balance(AccountRef::user($code, $user->id, 'USD')) !== 0) {
                $blockers[] = 'account.delete.blocker.balance';
                break;
            }
        }

        $sellerId = SellerProfile::query()->where('user_id', $user->id)->value('id');
        $seller = is_numeric($sellerId) ? (int) $sellerId : null;
        $openStatuses = [OrderStatus::PendingPayment->value, OrderStatus::PaymentReview->value, OrderStatus::Paid->value, OrderStatus::Delivering->value, OrderStatus::Delivered->value];
        if (Order::query()->whereIn('status', $openStatuses)->where(fn ($q) => $q->where('buyer_id', $user->id)->when($seller !== null, fn ($q) => $q->orWhere('seller_profile_id', $seller)))->exists()) {
            $blockers[] = 'account.delete.blocker.orders';
        }

        $orders = Order::query()->where('buyer_id', $user->id)->when($seller !== null, fn ($q) => $q->orWhere('seller_profile_id', $seller))->select('id');
        if (Dispute::query()->whereIn('order_id', $orders)->whereNotIn('status', ['resolved', 'cancelled'])->exists()) {
            $blockers[] = 'account.delete.blocker.disputes';
        }

        if (WithdrawalRequest::query()->where('user_id', $user->id)->whereIn('status', ['requested', 'approved', 'processing'])->exists()) {
            $blockers[] = 'account.delete.blocker.withdrawals';
        }

        return $blockers;
    }

    /**
     * @param  array<string, string>  $proofs
     * @return list<string>
     */
    private function verify(User $user, array $proofs): array
    {
        $proofs = array_filter($proofs, static fn (string $v, string $k): bool => in_array($k, self::FACTORS, true) && $v !== '', ARRAY_FILTER_USE_BOTH);
        if (count($proofs) !== 2) {
            throw AccountDeletionException::because('account.delete.error.two_factors');
        }

        foreach ($proofs as $factor => $value) {
            $ok = match ($factor) {
                'email' => is_string($hash = Cache::get(self::codeKey($user))) && Hash::check(trim($value), $hash),
                'privacy' => SetPrivacyPassword::verify($user, $value),
                default => $this->authenticator($user, $value),
            };
            if (! $ok) {
                throw AccountDeletionException::because('account.delete.error.factor.'.$factor);
            }
        }
        Cache::forget(self::codeKey($user));

        return array_keys($proofs);
    }

    private function anonymise(AccountDeletionRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user_id);
            $tag = 'deleted_'.$user->id;

            $profile = SellerProfile::query()->where('user_id', $user->id)->first();
            if ($profile !== null && $profile->status !== SellerStatus::Banned) {
                $this->sellers->ban($profile, null, 'account_deleted');
            }
            Affiliate::query()->where('user_id', $user->id)->update(['status' => Affiliate::SUSPENDED]);

            $user->forceFill([
                'username' => $tag,
                'name' => 'Deleted user',
                'email' => $tag.'@deleted.invalid',
                'phone' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'status' => UserStatus::Deleted,
            ])->save();
            UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $request->forceFill(['status' => AccountDeletionRequest::COMPLETED, 'completed_at' => now()])->save();
            $this->audit->log('account.deleted', $user, after: ['request' => $request->id]);
        });
    }

    private function authenticator(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled() || ! is_string($user->two_factor_secret)) {
            return false;
        }
        $secret = decrypt($user->two_factor_secret);

        return is_string($secret) && app(TwoFactorAuthenticationProvider::class)->verify($secret, trim($code));
    }

    private static function codeKey(User $user): string
    {
        return 'account-delete-code:'.$user->id;
    }
}
