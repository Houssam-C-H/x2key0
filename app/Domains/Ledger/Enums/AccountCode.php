<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Enums;

/**
 * Chart of accounts (spec 7.1), one account per (code, owner, currency). PSP clearing accounts are per
 * provider: `PSP_CLEARING:{provider}`. User-owned liabilities are shown to users as `−balance`.
 */
enum AccountCode: string
{
    case PspClearing = 'PSP_CLEARING';
    case Bank = 'BANK';
    case OrderEscrow = 'ORDER_ESCROW';
    case BuyerCredits = 'BUYER_CREDITS';
    case SellerPending = 'SELLER_PENDING';
    case SellerAvailable = 'SELLER_AVAILABLE';
    case SellerReserve = 'SELLER_RESERVE';
    case SellerFrozen = 'SELLER_FROZEN';
    case SellerDeposit = 'SELLER_DEPOSIT';
    case SellerInsuredPending = 'SELLER_INSURED_PENDING';
    case AffiliatePending = 'AFFILIATE_PENDING';
    case AffiliateAvailable = 'AFFILIATE_AVAILABLE';
    case PayoutInTransit = 'PAYOUT_IN_TRANSIT';
    case TaxPayable = 'TAX_PAYABLE';
    case RevCommission = 'REV_COMMISSION';
    case RevBuyerFee = 'REV_BUYER_FEE';
    case RevPayoutFee = 'REV_PAYOUT_FEE';
    case ExpPspFees = 'EXP_PSP_FEES';
    case ExpChargebackLoss = 'EXP_CHARGEBACK_LOSS';
    case ExpChargebackFees = 'EXP_CHARGEBACK_FEES';
    case ExpPromotions = 'EXP_PROMOTIONS';
    case ExpAffiliate = 'EXP_AFFILIATE';

    public function type(): AccountType
    {
        return match ($this) {
            self::PspClearing, self::Bank => AccountType::Asset,
            self::RevCommission, self::RevBuyerFee, self::RevPayoutFee => AccountType::Revenue,
            self::ExpPspFees, self::ExpChargebackLoss, self::ExpChargebackFees, self::ExpPromotions, self::ExpAffiliate => AccountType::Expense,
            default => AccountType::Liability,
        };
    }

    /** Owned by a user (buyer credits, seller and affiliate balances); the rest belongs to the platform. */
    public function isUserOwned(): bool
    {
        return in_array($this, [
            self::BuyerCredits, self::SellerPending, self::SellerAvailable, self::SellerReserve, self::SellerFrozen,
            self::SellerDeposit, self::SellerInsuredPending, self::AffiliatePending, self::AffiliateAvailable, self::PayoutInTransit,
        ], true);
    }
}
