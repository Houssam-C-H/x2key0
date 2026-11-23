<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Support;

use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Identity\Models\User;
use App\Domains\Payouts\Models\PayoutMethodType;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Shared\Money\Amount;
use App\Shared\Support\Settings;

/**
 * Who may withdraw and what it costs (spec 6.3, 1.8, Part 27):
 *
 * - an open store (active or on vacation) with payouts enabled by finance, identity verified and 2FA on;
 * - amount ≥ max(`withdrawal_min_amount`, method minimum);
 * - fee = max(`withdrawal_fee_amount`, method fee) where method fee = bps of the amount (half-up) + fixed;
 *   the platform minimum fee and the method fee are not added up. Payout = amount − fee, in USD (FX later).
 */
final class WithdrawalRules
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Unmet requirements (empty = may withdraw).
     *
     * @return list<string> identity | two_factor | payouts_enabled | store
     */
    public function blockers(User $user): array
    {
        $user->loadMissing(['sellerProfile', 'certifications']);
        $profile = $user->sellerProfile;
        $blockers = [];

        if ($profile === null || ! in_array($profile->status, [SellerStatus::Active, SellerStatus::Vacation], true)) {
            $blockers[] = 'store';
        }
        if (! $user->isCertified(CertificationType::Identity)) {
            $blockers[] = 'identity';
        }
        if (! $user->hasTwoFactorEnabled()) {
            $blockers[] = 'two_factor';
        }
        if ($profile !== null && ! $profile->payouts_enabled) {
            $blockers[] = 'payouts_enabled';
        }

        return $blockers;
    }

    public function minimum(PayoutMethodType $type): int
    {
        return max($this->settings->int('withdrawal_min_amount'), $type->min_amount);
    }

    public function fee(PayoutMethodType $type, int $amount, string $currency): int
    {
        $methodFee = Amount::toMinor(Amount::bps(Amount::ofMinor($amount, $currency), $type->fee_bps)) + $type->fee_fixed_amount;

        return max($this->settings->int('withdrawal_fee_amount'), $methodFee);
    }
}
