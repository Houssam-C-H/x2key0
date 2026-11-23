<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

/**
 * The 15 category types (spec 1.2 / 5.2). Order = display order in menus and search.
 */
enum CategoryTypeCode: string
{
    case Currency = 'currency';
    case TopUp = 'top_up';
    case Items = 'items';
    case Boosting = 'boosting';
    case Accounts = 'accounts';
    case VideoGames = 'video_games';
    case GiftCards = 'gift_cards';
    case CouponsSavings = 'coupons_savings';
    case Subscriptions = 'subscriptions';
    case SoftwareApps = 'software_apps';
    case GameDlc = 'game_dlc';
    case GamingEcards = 'gaming_ecards';
    case EgiftCards = 'egift_cards';
    case RetailGiftCards = 'retail_gift_cards';
    case MobileRecharges = 'mobile_recharges';

    /** Translation key for the label. */
    public function labelKey(): string
    {
        return 'category.'.$this->value;
    }

    /** The 8 tiles shown in the home hero (spec 1.3). */
    public function inHero(): bool
    {
        return in_array($this, [
            self::Currency, self::TopUp, self::Items, self::Boosting,
            self::Accounts, self::VideoGames, self::GiftCards, self::CouponsSavings,
        ], true);
    }
}
