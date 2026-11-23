<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\PayoutMethod;
use App\Domains\Payouts\Models\PayoutMethodType;
use App\Domains\Payouts\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;

/**
 * Withdrawal Account (spec 1.8 "Add New Account"): add a destination for an enabled catalog method (details
 * encrypted, masked label shown), choose the default, remove one with no open withdrawal (kept disabled for
 * the records). At most 10 usable methods per user.
 */
final class ManagePayoutMethods
{
    public const int MAX_METHODS = 10;

    /**
     * @param  array<string, string>  $details
     */
    public function add(User $user, string $type, string $currency, array $details): PayoutMethod
    {
        $catalog = PayoutMethodType::query()->where('code', $type)->where('is_enabled', true)->first();
        if ($catalog === null || ! in_array($currency, $catalog->currencies, true)) {
            throw PayoutException::because('payout.error.method');
        }

        $clean = [];
        foreach ($catalog->fields as $field) {
            $value = trim($details[$field] ?? '');
            if ($value === '') {
                throw PayoutException::because('payout.error.details');
            }
            $clean[$field] = mb_substr($value, 0, 191);
        }

        return DB::transaction(function () use ($user, $catalog, $currency, $clean): PayoutMethod {
            $count = PayoutMethod::query()->where('user_id', $user->id)->usable()->lockForUpdate()->count();
            if ($count >= self::MAX_METHODS) {
                throw PayoutException::because('payout.error.too_many', ['count' => self::MAX_METHODS]);
            }

            $method = new PayoutMethod;
            $method->forceFill([
                'user_id' => $user->id,
                'type' => $catalog->code,
                'payout_currency' => $currency,
                'details_encrypted' => $clean,
                'masked_label' => $catalog->name.' · '.self::mask($clean['iban'] ?? $clean['wallet_address'] ?? $clean['email'] ?? (string) reset($clean)),
                'is_default' => $count === 0,
            ])->save();

            return $method->refresh();
        });
    }

    public function makeDefault(User $user, PayoutMethod $method): void
    {
        self::assertOwned($user, $method);

        DB::transaction(function () use ($user, $method): void {
            PayoutMethod::query()->where('user_id', $user->id)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true])->save();
        });
    }

    public function remove(User $user, PayoutMethod $method): void
    {
        self::assertOwned($user, $method);

        $open = WithdrawalRequest::query()->where('payout_method_id', $method->id)->whereIn('status', WithdrawalStatus::openValues())->exists();
        if ($open) {
            throw PayoutException::because('payout.error.method_in_use');
        }

        $method->forceFill(['disabled_at' => now(), 'is_default' => false])->save();
    }

    /** "john.doe@mail.com" → "jo•••@mail.com", "GB33BUKB20201555555555" → "••••5555". */
    public static function mask(string $value): string
    {
        if (str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);

            return mb_substr($name, 0, 2).'•••@'.$domain;
        }

        return '••••'.mb_substr(preg_replace('/\s+/', '', $value) ?? $value, -4);
    }

    private static function assertOwned(User $user, PayoutMethod $method): void
    {
        if ($method->user_id !== $user->id || $method->disabled_at !== null) {
            throw PayoutException::because('payout.error.method');
        }
    }
}
